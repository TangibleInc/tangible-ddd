<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Support\Log;

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
 * Ports: the ledger is required; the boundary is the argument, else
 * HostDefaults::get(ITransactionBoundary::class), else none.
 */
final class WorkflowIgniter {

  public function __construct(
    private readonly IWorkflowIgnitionLedger $ledger,
    private readonly ?ITransactionBoundary $boundary = null,
    private readonly ?LoggerInterface $logger = null,
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
          $this->ignite($workflow, $event, $event_id);
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

    if ($key === '') {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s ignited by %s without a dedup key (no event id): a redelivery can ignite it again',
        $kind, get_class($fact)
      ), 'warning');
      $this->atomically(static fn () => $workflow->save_ignited($new));
      return $this->start($workflow, $new, null);
    }

    $won = $this->atomically(function () use ($workflow, $new, $key, $kind, $eventId): bool {
      if (!$this->ledger->claim($key, $kind, $eventId === '' ? null : $eventId)) {
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

    if (!$won) {
      return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::AlreadyIgnited, $key, $this->ledger->find($key)?->workflowId);
    }
    return $this->start($workflow, $new, $key);
  }

  private function start(IStartsFromFact $workflow, \TangibleDDD\Domain\BehaviourWorkflow $new, ?string $key): WorkflowIgnitionResult {
    try {
      $workflow->start_ignited($new);
    } catch (\Throwable $e) {
      Log::write($this->logger, sprintf(
        '[ddd-workflow] %s workflow #%d ignited (key %s) but failed to start: %s',
        $workflow->workflow_kind(), (int) $new->get_id(), $key ?? '-', $e->getMessage()
      ), 'error');
      return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::Ignited, $key, $new->get_id(), $e);
    }
    return new WorkflowIgnitionResult(WorkflowIgnitionOutcome::Ignited, $key, $new->get_id());
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
