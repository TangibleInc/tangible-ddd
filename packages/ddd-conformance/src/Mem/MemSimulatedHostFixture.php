<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\FreshRun;
use TangibleDDD\Conformance\Support\FreshProcessBoot;
use TangibleDDD\Conformance\WebRequests;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Testing\InMemoryTransactional;

/**
 * NOT a conformance host. The mem host plus an in-process SIMULATION of the
 * multi-process seams (FreshProcesses, WebRequests), so the scenario cases
 * for ids that are `-` on mem (FreshProcessScenarios, ConcurrencyScenarios,
 * WebStartScenarios) are exercised in this package before pdo, wp and sf
 * run them for real. Its test classes carry `#[Group('simulated')]`, never
 * `mem`, and prove nothing about any host.
 *
 * - A "fresh php process" is a new object graph over the same mem stores
 *   (the "database"): a new ProcessRunner (in-band), a new lock session on
 *   the same raw lock, a new subscription registry booted with
 *   FreshProcessBoot, and its own ProcessJournal statics (saved and
 *   restored around it, as another process's statics would be invisible).
 * - A "kill" at a point snapshots every enlisted participant there and
 *   restores that snapshot after the run, so nothing the dying process
 *   would have done afterwards (compensation, saves) survives.
 * - The fresh delivery stage consumes the mem transport like a queue; a
 *   fact's class comes from the outbox record the bus appended.
 * - A "web request" is a plain call; the pooled-DSN boot refusal is the sf
 *   rule (register 5.2) restated, not sf's code.
 */
final class MemSimulatedHostFixture extends MemHostFixture implements FreshProcesses, WebRequests {

  /** @var array<int, true> transport submission indexes a fresh delivery stage consumed */
  private array $consumed = [];

  /**
   * @param bool $abortOnStatementError model an engine that aborts the
   *   transaction on a statement error (Postgres 25P02): its COMMIT then
   *   fails and nothing persists (the CR sf-7 branch of cmd.commit-failure)
   */
  public function __construct(
    StartMode $startMode = StartMode::InBand,
    private readonly bool $abortOnStatementError = false,
  ) {
    parent::__construct(false, $startMode);
  }

  public function runFailingStatement(): void {
    if ($this->abortOnStatementError) {
      $this->boundary->failNextCommit('current transaction is aborted (25P02, simulated)');
    }
    parent::runFailingStatement();
  }

  public function hostName(): string {
    return 'mem-simulated';
  }

  // ── FreshProcesses ───────────────────────────────────────────────────────

  public function publishInFreshProcess(DomainEvent&IIntegrationEvent $fact, bool $killAfterCommit): string {
    $id = null;
    $this->inFreshProcess(function () use ($fact, &$id): void {
      $bus = $this->commandBus([CreateWidget::class => function () use ($fact): void {
        $this->events()->record($fact);
      }]);
      $bus->handle(new CreateWidget('fresh-publish'));
      $id = PublishedFacts::id_of($fact);
      // mem has no shutdown relay: a normal exit and a kill after COMMIT leave the same state.
    });
    return $id ?? throw new \LogicException('the fresh process published nothing');
  }

  public function drainInFreshProcess(): FreshRun {
    $report = null;
    $errors = [];
    $this->inFreshProcess(function (ProcessRunner $runner, ISubscriptionRegistry $registry) use (&$report, &$errors): void {
      $report = (new Drain(
        $this->relayProcessor($this->outbox, $this->outboxConfig->batch_size),
        $this->wakeups,
        $this->wakeFaults->wrap($runner),
        $this->queueConsumer($registry, $errors),
        $runner,
        $this->clock,
        $this->logger,
      ))->runOnce();
    });
    return new FreshRun(
      relayed: $report?->relay?->accepted ?? [],
      delivered: $report?->delivered ?? 0,
      errors: [...($report?->errors ?? []), ...$errors],
    );
  }

  public function deliverInFreshProcess(string $eventClass, array $wrapped): FreshRun {
    $outcome = null;
    $this->inFreshProcess(function (ProcessRunner $runner, ISubscriptionRegistry $registry) use ($eventClass, $wrapped, &$outcome): void {
      $outcome = (new IntegrationDelivery($registry, $this->ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger))->deliver($eventClass, $wrapped);
    });
    return new FreshRun(delivered: 1, errors: array_map(static fn (string $s) => "subscriber $s failed", $outcome?->failed ?? []));
  }

  public function startInFreshProcess(LongProcess $process, ?string $dieAfterCommand = null): FreshRun {
    $died = $this->inFreshProcess(static function (ProcessRunner $runner) use ($process): void {
      $runner->start($process);
    }, $dieAfterCommand);
    return new FreshRun(died: $died, processId: $process->get_id());
  }

  // ── WebRequests ──────────────────────────────────────────────────────────

  public function inWebRequest(callable $fn): mixed {
    return $fn();
  }

  public function bootInBandStartOnPooledDsn(): ?\Throwable {
    // sf's boot rule, restated: an in-band first step needs the direct connection.
    return new \LogicException('ddd.process.inband_start: true requires a direct (non-pooled) connection (simulated)');
  }

  // ── internals ────────────────────────────────────────────────────────────

  /**
   * Run $fn as a fresh php process; returns whether it was killed.
   *
   * @param callable(ProcessRunner, ISubscriptionRegistry): void $fn
   */
  private function inFreshProcess(callable $fn, ?string $dieAfterCommand = null): bool {
    $journal = [ProcessJournal::$steps, ProcessJournal::$sent, ProcessJournal::$onSend];
    ProcessJournal::$steps = [];
    ProcessJournal::$sent = [];
    ProcessJournal::$onSend = null;

    $registry = new SubscriptionRegistry();
    $lock = new ReentrantProcessLock($this->rawLock, $this->logger);
    $runner = new ProcessRunner(
      $this->config, null, $lock, $this->processStore, $this->wakeups, $registry,
      $this->boundary, $this->clock, StartMode::InBand, $this->logger,
    );
    FreshProcessBoot::boot($runner, $registry, $this->rows, $this->boundary);

    $killedAt = null;
    if ($dieAfterCommand !== null) {
      ProcessJournal::$onSend = function (StepCommand $c) use ($dieAfterCommand, &$killedAt): void {
        if ($killedAt === null && $c->label === $dieAfterCommand) {
          $killedAt = array_map(static fn (InMemoryTransactional $p) => [$p, $p->snapshotState()], $this->participants());
          throw new \RuntimeException("killed after $dieAfterCommand committed (simulated SIGKILL)");
        }
      };
    }

    try {
      $fn($runner, $registry);
    } catch (\Throwable $e) {
      if ($killedAt === null) {
        throw $e;
      }
    } finally {
      foreach ($killedAt ?? [] as [$participant, $state]) {
        $participant->restoreState($state);
      }
      $lock->forceReleaseAll();
      Correlation::reset();
      [ProcessJournal::$steps, ProcessJournal::$sent, ProcessJournal::$onSend] = $journal;
      ProcessJournal::bind($this->rows, $this->boundary);
    }
    return $killedAt !== null;
  }

  /** @return list<InMemoryTransactional> */
  private function participants(): array {
    return [...$this->competitorParticipants(), $this->transport];
  }

  /** The fresh worker's delivery stage: consume due transport messages like a queue. */
  private function queueConsumer(ISubscriptionRegistry $registry, array &$errors): IDeliveryWorker {
    $delivery = new IntegrationDelivery($registry, $this->ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger);
    $consume = function (\DateTimeImmutable $now, int $limit) use ($delivery, &$errors): int {
      $types = [];
      foreach ($this->facts->observed as $o) {
        $types[$o['record']->event_id] = $o['record']->event_type;
      }
      $classes = FreshProcessBoot::factClasses();
      $n = 0;
      foreach ($this->transport->submissions as $i => $s) {
        if ($n >= $limit || isset($this->consumed[$i]) || $s['ref'] === null || $s['due_at'] > $now) {
          continue;
        }
        $class = $classes[$types[$s['event_id']] ?? ''] ?? null;
        if ($class === null) {
          continue;
        }
        $this->consumed[$i] = true;
        foreach ($delivery->deliver($class, $s['envelope'])->failed as $sid) {
          $errors[] = "subscriber $sid failed on {$s['event_id']}";
        }
        $n++;
      }
      return $n;
    };

    return new class($consume) implements IDeliveryWorker {
      public function __construct(private readonly \Closure $consume) {}

      public function runDue(\DateTimeImmutable $now, int $limit): int {
        return ($this->consume)($now, $limit);
      }
    };
  }
}
