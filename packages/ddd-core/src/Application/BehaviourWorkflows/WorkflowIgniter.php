<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;

/**
 * D10: BehaviourWorkflow ignition from a fact, deduped through the workflow
 * ignition ledger (register 3.11, ruling #78; scenario
 * workflow.fact-ignition-once).
 *
 * register() subscribes the workflow to every #[StartsOn] fact on its class,
 * at Subscriber::IGNITION (after plain listeners, before process resumes),
 * id `{prefix}/workflow-ignition:{workflow class}@{fact class}`. The
 * delivery ledger skips a subscriber that already succeeded for an event
 * id; the ignition ledger is what makes two different facts with the same
 * key (two cron ticks in one minute) ignite once, and what holds across
 * concurrent workers.
 *
 * ignite(): see IStartsFromFact. The claim, the save and the attach run in
 * one ITransactionBoundary::run (joined when the caller has one open), so a
 * failed save releases the key. Without a boundary the claim is released
 * explicitly when the save fails.
 *
 * Start and restart (fix round 1). After the ignition commits, the igniter
 * claims the key's start marker (WorkflowIgnitionKey::startMarker, a second
 * ledger entry) and runs start_ignited(). If the start throws, the marker
 * is released, the error is logged and returned as startError, and the
 * registered subscriber rethrows it, so the delivery ledger records a failed
 * attempt and redelivers the fact. Any later ignition with the key (that
 * retry, or another fact with the same key) finds the workflow attached but
 * no start marker: it claims the marker, loads the workflow
 * (ILoadsIgnitedWorkflow::load_ignited, which StartsFromFacts provides) and,
 * if it is still active, starts it (outcome Restarted). The marker claim is
 * the gate, so the workflow is started by exactly one caller.
 *
 * Completed starts and dead starters (fix round 2). A start that returns
 * attaches the workflow id to its marker: an attached marker means
 * "started". A marker with no workflow is a start in flight, or a worker
 * that died inside start_ignited(). While it is younger than
 * $stale_start_seconds (default 900), ignite() returns AlreadyIgnited with
 * startPending, and the registered subscriber throws WorkflowStartPending,
 * so the delivery ledger keeps the fact failed and retries it (a start that
 * never completes dead-letters the fact: that is where an operator sees
 * it). Once older, the next ignition with the key releases and re-claims the
 * marker in one transaction and, if the loaded workflow is still active,
 * starts it again (outcome Restarted, logged as a warning). A start that
 * outlives $stale_start_seconds can therefore run twice, which
 * start_ignited()'s re-run tolerance (IStartsFromFact) covers.
 *
 * Inside an open transaction. ignite() joins a transaction that is already
 * open (a delivery that runs its subscribers in one): the claim, the save,
 * the attach, the start marker and start_ignited() then all run in the
 * caller's transaction, so the start is not "after commit". If the caller
 * rolls back, the ignition and the marker roll back with it and the
 * redelivery ignites again; side effects of the start that are not
 * transactional are not undone. A host that cannot accept that calls
 * ignite() outside a transaction, or hands the start off from
 * start_ignited() to a durable queue enlisted in the same transaction.
 *
 * Stale claims (no boundary). Without a transaction boundary, a crash
 * between claim() and attach() leaves the key claimed with no workflow.
 * Such an entry older than $stale_claim_seconds (default 900) is released
 * and claimed again by the next ignition with the key; a younger one is
 * treated as in flight (AlreadyIgnited, no workflow id). With a boundary the
 * claim and the attach commit together and no entry is ever reclaimed.
 *
 * Ports: the ledger is required; the boundary is the argument, else
 * HostDefaults::get(ITransactionBoundary::class), else none; the clock is
 * the argument, else HostDefaults' IClock, else the system clock.
 */
final class WorkflowIgniter {

  public function __construct(
    private readonly IWorkflowIgnitionLedger $ledger,
    private readonly ?ITransactionBoundary $boundary = null,
    private readonly ?LoggerInterface $logger = null,
    private readonly ?IClock $clock = null,
    private readonly int $stale_claim_seconds = 900,
    private readonly int $stale_start_seconds = 900,
  ) {}

  /**
   * @throws \InvalidArgumentException when the class declares no #[StartsOn]
   *   or names a class that is not an integration event
   */
  public function register(IStartsFromFact $workflow, ISubscriptionRegistry $registry, string $consumerPrefix): void {
    $class = get_class($workflow);
    $facts = array_map(
      static fn (\ReflectionAttribute $a) => $a->newInstance()->event_class,
      (new \ReflectionClass($workflow))->getAttributes(StartsOn::class),
    );
    if ($facts === []) {
      throw new \InvalidArgumentException("$class implements IStartsFromFact but declares no #[StartsOn(SomeFact::class)]");
    }

    foreach (array_unique($facts) as $fact) {
      if (!is_a($fact, IIntegrationEvent::class, true)) {
        throw new \InvalidArgumentException("$class #[StartsOn($fact)]: $fact must implement IIntegrationEvent");
      }
      $registry->add(new Subscriber(
        $consumerPrefix . '/workflow-ignition:' . $class . '@' . $fact,
        Subscriber::IGNITION,
        $fact,
        function (IIntegrationEvent $event, string $event_id = '') use ($workflow): void {
          $result = $this->ignite($workflow, $event, $event_id);
          if ($result->startError !== null) {
            throw $result->startError; // the delivery retries; the retry restarts the workflow
          }
          if ($result->startPending) {
            // Not done until the start completes: the delivery retries (and dead-letters, visibly, if it never does).
            throw new WorkflowStartPending((string) $result->dedupKey, (int) $result->workflowId);
          }
        },
      ));
    }
  }

  public function ignite(IStartsFromFact $workflow, IIntegrationEvent $fact, string $eventId = ''): WorkflowIgnitionResult {
    $new = $workflow->workflow_from_fact($fact);
    if ($new === null) {
      return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::Declined);
    }

    $kind = $workflow->workflow_kind();
    $key = $workflow->ignition_key($fact, $eventId);
    $event = $eventId === '' ? null : $eventId;

    if ($key === '') {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s ignited by %s without a dedup key (no event id): a redelivery can ignite it again',
        $kind, get_class($fact)
      ), 'warning');
      $this->atomically(static fn () => $workflow->save_ignited($new));
      return $this->start($workflow, $new, null, $event);
    }

    if ($this->claim_and_save($workflow, $new, $key, $kind, $event)) {
      return $this->start($workflow, $new, $key, $event);
    }

    $entry = $this->ledger->find($key);
    if ($entry !== null && $entry->workflowId === null && $this->is_stale($entry)) {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s: ignition key %s was claimed at %s but no workflow was attached (no transaction boundary, the worker died); reclaiming it',
        $kind, $key, $entry->createdAt->format(\DateTimeInterface::ATOM)
      ), 'warning');
      $this->atomically(fn () => $this->ledger->release($key));
      if ($this->claim_and_save($workflow, $new, $key, $kind, $event)) {
        return $this->start($workflow, $new, $key, $event);
      }
      $entry = $this->ledger->find($key);
    }

    if ($entry?->workflowId !== null) {
      return $this->restart($workflow, $key, $entry->workflowId, $event);
    }
    return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::AlreadyIgnited, $key, null);
  }

  /** The claim, the save and the attach as one state change. False = the key already ignited. */
  private function claim_and_save(IStartsFromFact $workflow, BehaviourWorkflow $new, string $key, string $kind, ?string $eventId): bool {
    return $this->atomically(function () use ($workflow, $new, $key, $kind, $eventId): bool {
      if (!$this->ledger->claim($key, $kind, $eventId)) {
        return false;
      }
      try {
        $workflow->save_ignited($new);
        $id = $new->get_id() ?? throw new \LogicException("$kind::save_ignited() did not set the workflow id");
        $this->ledger->attach($key, $id);
      } catch (\Throwable $e) {
        if ($this->boundary() === null) {
          $this->ledger->release($key); // no transaction to roll the claim back
        }
        throw $e;
      }
      return true;
    });
  }

  /**
   * The key ignited workflow #$workflowId earlier: start it if nobody has
   * (no start marker) and it is still active.
   */
  private function restart(IStartsFromFact $workflow, string $key, int $workflowId, ?string $eventId): WorkflowIgnitionResult {
    $already = new WorkflowIgnitionResult(WorkflowIgnitionOutcome::AlreadyIgnited, $key, $workflowId);
    $marker = WorkflowIgnitionKey::startMarker($key);
    $held = $this->ledger->find($marker);
    if ($held !== null && $held->workflowId !== null) {
      return $already; // the start completed
    }
    if (!$workflow instanceof ILoadsIgnitedWorkflow && !method_exists($workflow, 'load_ignited')) {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s workflow #%d (key %s) ignited but was never started, and %s cannot load it (implement ILoadsIgnitedWorkflow)',
        $workflow->workflow_kind(), $workflowId, $key, get_class($workflow)
      ), 'error');
      return $already;
    }
    if ($held !== null) {
      // A start in flight, or a worker that died inside start_ignited().
      if ($this->age_of($held) < $this->stale_start_seconds) {
        return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::AlreadyIgnited, $key, $workflowId, null, true);
      }
      try {
        $stuck = $workflow->load_ignited($workflowId);
      } catch (\Throwable $e) {
        return $this->failed_start($workflow, $workflowId, $key, $e, WorkflowIgnitionOutcome::AlreadyIgnited);
      }
      if ($stuck === null || !$stuck->is_active()) {
        return $already; // gone, or it ran to an end: nothing to restart
      }
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s workflow #%d (key %s): start marker claimed at %s never completed (the worker died inside start_ignited?); reclaiming it',
        $workflow->workflow_kind(), $workflowId, $key, $held->createdAt->format(\DateTimeInterface::ATOM)
      ), 'warning');
      $reclaimed = $this->atomically(function () use ($marker, $workflow, $eventId): bool {
        $this->ledger->release($marker);
        return $this->ledger->claim($marker, $workflow->workflow_kind(), $eventId);
      });
      if (!$reclaimed) {
        return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::AlreadyIgnited, $key, $workflowId, null, true);
      }
      return $this->run_start($workflow, $stuck, $key, $marker, WorkflowIgnitionOutcome::Restarted);
    }
    if (!$this->atomically(fn () => $this->ledger->claim($marker, $workflow->workflow_kind(), $eventId))) {
      return $already;
    }

    try {
      $loaded = $workflow->load_ignited($workflowId);
    } catch (\Throwable $e) {
      $this->release_marker($marker);
      return $this->failed_start($workflow, $workflowId, $key, $e, WorkflowIgnitionOutcome::AlreadyIgnited);
    }
    if ($loaded === null || !$loaded->is_active()) {
      return $already; // gone, or it ran to an end: the marker stays, nothing to restart
    }
    Log::write($this->logger, sprintf(
      '[ddd-workflow] %s workflow #%d (key %s) ignited but was never started; starting it now',
      $workflow->workflow_kind(), $workflowId, $key
    ), 'warning');
    return $this->run_start($workflow, $loaded, $key, $marker, WorkflowIgnitionOutcome::Restarted);
  }

  /** The winner's start, gated by the start marker (a concurrent restart may have taken it). */
  private function start(IStartsFromFact $workflow, BehaviourWorkflow $new, ?string $key, ?string $eventId): WorkflowIgnitionResult {
    if ($key === null) {
      return $this->run_start($workflow, $new, null, null, WorkflowIgnitionOutcome::Ignited);
    }
    $marker = WorkflowIgnitionKey::startMarker($key);
    if (!$this->atomically(fn () => $this->ledger->claim($marker, $workflow->workflow_kind(), $eventId))) {
      return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::Ignited, $key, $new->get_id());
    }
    return $this->run_start($workflow, $new, $key, $marker, WorkflowIgnitionOutcome::Ignited);
  }

  private function run_start(IStartsFromFact $workflow, BehaviourWorkflow $run, ?string $key, ?string $marker, WorkflowIgnitionOutcome $outcome): WorkflowIgnitionResult {
    try {
      $workflow->start_ignited($run);
    } catch (\Throwable $e) {
      if ($marker !== null) {
        $this->release_marker($marker); // the next delivery with the key restarts it
      }
      return $this->failed_start($workflow, (int) $run->get_id(), $key, $e, $outcome);
    }
    if ($marker !== null && $run->get_id() !== null) {
      $this->complete_marker($marker, (int) $run->get_id());
    }
    return new WorkflowIgnitionResult($outcome, $key, $run->get_id());
  }

  /** Attach the workflow to its start marker: the start completed. */
  private function complete_marker(string $marker, int $workflowId): void {
    try {
      $this->atomically(fn () => $this->ledger->attach($marker, $workflowId));
    } catch (\Throwable $e) {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] workflow #%d started, but its start marker %s could not record it (after %d s a later fact with the key may start it again): %s',
        $workflowId, $marker, $this->stale_start_seconds, $e->getMessage()
      ), 'error');
    }
  }

  private function failed_start(IStartsFromFact $workflow, int $workflowId, ?string $key, \Throwable $e, WorkflowIgnitionOutcome $outcome): WorkflowIgnitionResult {
    Log::write($this->logger, sprintf(
      '[ddd-workflow] %s workflow #%d ignited (key %s) but failed to start: %s',
      $workflow->workflow_kind(), $workflowId, $key ?? '-', $e->getMessage()
    ), 'error');
    return new WorkflowIgnitionResult($outcome, $key, $workflowId, $e);
  }

  private function release_marker(string $marker): void {
    try {
      $this->atomically(fn () => $this->ledger->release($marker));
    } catch (\Throwable $e) {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] could not release start marker %s after a failed start (release it to let the workflow restart): %s',
        $marker, $e->getMessage()
      ), 'error');
    }
  }

  /** A claim with no workflow attached, old enough to be a dead worker's (only without a boundary). */
  private function is_stale(WorkflowIgnition $entry): bool {
    if ($this->boundary() !== null) {
      return false;
    }
    return $this->age_of($entry) >= $this->stale_claim_seconds;
  }

  private function age_of(WorkflowIgnition $entry): int {
    $clock = $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
    return $clock->now()->getTimestamp() - $entry->createdAt->getTimestamp();
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function atomically(callable $work): mixed {
    $boundary = $this->boundary();
    if ($boundary === null || $boundary->isActive()) {
      return $work();
    }
    return $boundary->run($work);
  }

  private function boundary(): ?ITransactionBoundary {
    return $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);
  }
}
