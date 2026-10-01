<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\EffectHost;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\ProcessDecodeFaults;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ProcessRow;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\RelayRace;
use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\StatementErrors;
use TangibleDDD\Conformance\Support\ConformanceConfig;
use TangibleDDD\Conformance\Support\ConnectionView;
use TangibleDDD\Conformance\Support\FaultInjectingAuditSink;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\Support\InterleavingProcessLock;
use TangibleDDD\Conformance\Support\RecordingLogger;
use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Conformance\Support\WakeHandoffFaults;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Conformance\WorkflowHost;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Audit\AuditEverything;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\RecordEffect;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryEffectJournal;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryParkingScheduler;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryRelayPauseStore;
use TangibleDDD\Testing\InMemoryTransactional;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Testing\RecordingFactObserver;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The mem host: every port is its ddd-core in-memory double
 * (packages/ddd-core/src/Testing), sharing one simulated connection through
 * InMemoryTransactionBoundary (outbox, ledger, the scenario rows, the
 * process store, the intents and a shared-connection transport are
 * enlisted, so a rollback restores them together).
 *
 * The pipeline is the real ddd-core one (CONF-1..3): CorrelationMiddleware
 * (act bracket + audit through the ports), TransactionalCommandMiddleware,
 * DomainEventsPublishMiddleware over OutboxIntegrationEventBus (port form),
 * the OutboxProcessor relay step (port form), IntegrationDelivery and
 * RuntimeReset; since wave 3 also the core ProcessRunner and Drain (W3C-R6).
 * Only the handler map is conformance-owned.
 *
 * HostDefaults gets the logger, the signal dispatcher and the clock (the
 * act bracket reads its clock there), never a transaction boundary:
 * cmd.no-boundary needs a bus without one.
 *
 * Workers (ProcessHost): worker 1 is this connection; worker n > 1 is a
 * second ProcessRunner + ReentrantProcessLock + subscription registry over
 * the SAME stores and the same raw InMemoryProcessLock, which then sees
 * worker 1's held keys as held (another session). The interleaving point
 * (before_next_lock) sits under worker 1's re-entrant lock.
 *
 * "Fresh schema" on mem is a fresh object graph built in set_up().
 */
class MemHostFixture implements HostFixture, AuditSinkFaults, RecordsSignals, ProcessHost, RelayRace, StatementErrors, ProcessDecodeFaults, EffectHost, WorkflowHost {

  public const START = '2026-10-01T00:00:00Z';
  public const CONSUMER_PREFIX = 'conformance';
  public const CONSUMER_VERSION = '0.0.0-conformance';

  protected ConformanceConfig $config;
  protected FrozenClock $clock;
  protected RecordingLogger $logger;
  protected RecordingSignalDispatcher $signals;
  protected InMemoryTransactionBoundary $boundary;
  protected ConnectionView $outbox_connection;
  protected InMemoryRelayPauseStore $pauses;
  protected InMemoryOutboxStore $outbox;
  protected RecordingOutboxStore $relay_store;
  protected InMemoryTransport $transport;
  protected InMemoryDeliveryLedger $ledger;
  protected SubscriptionRegistry $subscriptions;
  protected InMemoryProcessLock $raw_lock;
  protected InterleavingProcessLock $interleaving;
  protected ReentrantProcessLock $lock;
  protected EventsUnitOfWork $events;
  protected InMemoryScenarioRows $rows;
  protected OrderedListenerDispatcher $dispatcher;
  protected InMemoryAuditSink $audit;
  protected FaultInjectingAuditSink $audit_port;
  protected RecordingFactObserver $facts;
  protected OutboxConfig $outbox_config;
  protected InMemoryProcessStore $process_store;
  protected InMemoryWakeupScheduler $wakeups;
  protected WakeHandoffFaults $wake_faults;
  protected InMemoryEffectJournal $effect_journal;
  protected InMemoryWorkflowIgnitionLedger $ignitions;
  protected InMemoryWorkflowRepository $workflows;

  /** @var array<int, MemProcessWorker> */
  protected array $workers = [];

  /** @var list<array{0: class-string, 1: class-string}> */
  protected array $starts = [];

  /** @var list<class-string> */
  protected array $awaits = [];

  /** @var list<string> diagnostics the runtime classes logged, oldest first */
  public array $logs = [];

  private int $deliveredCursor = 0;

  private bool $crashAfterSubmit = false;

  private ?\Closure $race = null;

  /**
   * @param bool $parks_facts the scheduler carries facts (InMemoryParkingScheduler,
   *   ICarriesFacts; CR-W5CC-7): a fact resume that cannot lock is parked
   *   as a ResumeRetry instead of failing the resume subscriber
   */
  public function __construct(
    protected readonly bool $shared_connection = false,
    protected readonly StartMode $start_mode = StartMode::InBand,
    protected readonly bool $parks_facts = false,
  ) {}

  public function name(): string {
    return 'mem';
  }

  public function set_up(ScenarioContext $context): void {
    $this->resetStatics();

    $this->config = new ConformanceConfig(self::CONSUMER_PREFIX, self::CONSUMER_VERSION);
    $this->logger = new RecordingLogger(fn (string $m) => $this->logs[] = $m);
    $this->signals = new RecordingSignalDispatcher();
    $this->outbox_config = new OutboxConfig();
    $this->clock = new FrozenClock(new \DateTimeImmutable(self::START));
    $this->boundary = new InMemoryTransactionBoundary(NestedPolicy::Reject, $this->logger);
    $this->pauses = new InMemoryRelayPauseStore();
    $this->outbox_connection = new ConnectionView($this->boundary);
    $this->outbox = new InMemoryOutboxStore($this->clock, $this->pauses, $this->outbox_connection);
    $this->relay_store = new RecordingOutboxStore($this->outbox);
    // CONF-5: a shared-connection transport enlists in the boundary, so a
    // rolled-back relay transaction takes its submission with it.
    $this->transport = new InMemoryTransport($this->shared_connection, $this->boundary);
    $this->ledger = new InMemoryDeliveryLedger(self::CONSUMER_PREFIX);
    $this->subscriptions = new SubscriptionRegistry();
    $this->raw_lock = new InMemoryProcessLock();
    $this->interleaving = new InterleavingProcessLock($this->raw_lock);
    $this->lock = new ReentrantProcessLock($this->interleaving, $this->logger);
    $this->events = new EventsUnitOfWork();
    $this->rows = new InMemoryScenarioRows();
    $this->dispatcher = new OrderedListenerDispatcher();
    $this->audit = new InMemoryAuditSink();
    $this->audit_port = new FaultInjectingAuditSink($this->audit);
    $this->facts = new RecordingFactObserver();
    $this->process_store = new InMemoryProcessStore($this->clock);
    $this->wakeups = $this->parks_facts
      ? new InMemoryParkingScheduler($this->boundary)
      : new InMemoryWakeupScheduler($this->boundary);
    $this->process_store->attach_intents($this->wakeups);
    $this->wake_faults = new WakeHandoffFaults();
    $this->effect_journal = new InMemoryEffectJournal();
    $this->ignitions = new InMemoryWorkflowIgnitionLedger($this->clock);
    $this->workflows = new InMemoryWorkflowRepository();
    $this->workers = [];
    $this->starts = [];
    $this->awaits = [];

    $this->boundary->enlist($this->outbox);
    $this->boundary->enlist($this->ledger);
    $this->boundary->enlist($this->rows);
    $this->boundary->enlist($this->process_store);
    $this->boundary->enlist($this->wakeups);
    // EffectMiddleware stores outside any transaction; invalidate() inside a
    // repair command's transaction rolls back with it.
    $this->boundary->enlist($this->effect_journal);
    $this->boundary->enlist($this->ignitions);
    $this->boundary->enlist($this->workflows);

    HostDefaults::provide(LoggerInterface::class, $this->logger);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);
    HostDefaults::provide(IClock::class, $this->clock);

    RuntimeReset::register('conformance.events', fn () => $this->events->reset());
    RuntimeReset::guard($this->lock);

    // Step commands commit their effect row on this connection.
    ProcessJournal::bind($this->rows, $this->boundary);
  }

  public function tear_down(): void {
    $this->resetStatics();
    ProcessJournal::bind(null);
  }

  // ── time ─────────────────────────────────────────────────────────────────

  public function clock(): IClock {
    return $this->clock;
  }

  public function advance_clock(int $seconds): void {
    $this->clock->advance("+{$seconds} seconds");
  }

  // ── ports ────────────────────────────────────────────────────────────────

  public function boundary(): ITransactionBoundary {
    return $this->boundary;
  }

  public function outbox(): IOutboxStore {
    return $this->outbox;
  }

  public function outbox_admin(): IOutboxAdministration {
    return $this->outbox;
  }

  public function pauses(): IRelayPauseStore {
    return $this->pauses;
  }

  public function transport(): ITransport {
    return $this->transport;
  }

  public function ledger(): IDeliveryLedger {
    return $this->ledger;
  }

  public function subscriptions(): ISubscriptionRegistry {
    return $this->subscriptions;
  }

  public function lock(): IProcessLock {
    return $this->lock;
  }

  public function events(): EventsUnitOfWork {
    return $this->events;
  }

  public function rows(): ScenarioRows {
    return $this->rows;
  }

  // ── command pipeline ─────────────────────────────────────────────────────

  public function command_bus(array $handlers, BusOptions $options = new BusOptions()): CommandBus {
    return $this->bus($handlers, $options, null);
  }

  /**
   * @param array<class-string, callable(object): mixed> $handlers
   * @param Middleware|null $effects EffectMiddleware, placed between the act bracket and Transaction
   */
  protected function bus(array $handlers, BusOptions $options, ?Middleware $effects): CommandBus {
    $policy = $options->audit ? new AuditEverything() : new class implements IAuditPolicy {
      public function audits(object $command): bool {
        return false;
      }

      public function captures_parameters(object $command): bool {
        return false;
      }
    };

    return new CommandBus(...array_filter([
      new CorrelationMiddleware(
        $this->config,
        $this->events,
        new Redactor(),
        $this->audit_port,
        new FixedActorProvider(),
        $policy,
        new PhpEnvironmentProvider(['host' => 'mem']),
      ),
      $effects,
      new TransactionalCommandMiddleware($options->boundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, new OutboxIntegrationEventBus(
          null,
          $this->config,
          $this->facts,
          $this->clock,
          $this->outbox,
          $this->outbox_config,
        )),
      ),
      new HandlerMapMiddleware($handlers),
    ]));
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function fail_next_commit(string $reason): void {
    $this->boundary->fail_next_commit($reason);
  }

  public function audit_trail(): array {
    $names = [];
    foreach ($this->audit->opened as $open) {
      $names[$open->command_id] = $open->command_name;
    }
    return array_map(
      static fn ($close) => new AuditEntry($close->command_id, $names[$close->command_id] ?? '?', $close->status, $close->error['type'] ?? null),
      $this->audit->closed,
    );
  }

  // ── relay ────────────────────────────────────────────────────────────────

  /**
   * One step of the core OutboxProcessor (port form), batch size $limit,
   * over a RecordingOutboxStore so the report names event ids.
   */
  public function relay_once(int $limit = 50): RelayReport {
    $processor = $this->relay_processor($this->relay_store, $limit);

    $kept = null;
    if ($this->crashAfterSubmit) {
      $this->crashAfterSubmit = false;
      $processor->between_submit_and_accept(static function ($claim): void {
        throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
      });
    } elseif ($this->race !== null) {
      $competitor = $this->race;
      $this->race = null;
      $processor->between_submit_and_accept(function () use ($competitor, &$kept): void {
        $this->outbox_connection->run_elsewhere($competitor);
        // What the competitor committed on its own connection survives the
        // relay transaction's rollback: keep every participant but the
        // transport (the relay's own write) as the competitor left it.
        $kept = array_map(static fn (InMemoryTransactional $p) => [$p, $p->snapshot()], $this->competitor_participants());
      });
    }

    $this->relay_store->reset();
    $processor->process_batch();

    foreach ($kept ?? [] as [$participant, $state]) {
      $participant->restore($state);
    }
    return $this->relay_store->report();
  }

  public function reject_next_submission(?\Throwable $e = null): void {
    $this->transport->reject_next($e);
  }

  public function accept_next_without_ref(): void {
    $this->transport->drop_next_ref();
  }

  public function crash_next_relay(): void {
    $this->crashAfterSubmit = true;
  }

  public function transported(): array {
    return array_map(static fn (array $s) => new TransportedFact($s['event_id'], $s['due_at']), $this->held());
  }

  public function seed_legacy_fact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
    // No legacy schema on mem: the port record already carries the absolute time.
    $eventId = Uuid::v4();
    $this->outbox->append(new OutboxRecord(
      event_id: $eventId,
      event_type: $fact::name(),
      integration_action: $fact::integration_action(),
      correlation_id: Uuid::v4(),
      sequence: 1,
      command_id: null,
      payload: $fact->integration_payload(),
      due_at: $scheduledAt,
      max_attempts: $this->outbox_config->max_attempts,
    ));
    return $eventId;
  }

  // ── delivery ─────────────────────────────────────────────────────────────

  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    return $this->deliver_with($this->subscriptions, $eventClass, $wrapped);
  }

  public function deliver_transported(string $eventClass): array {
    $held = $this->held();
    $outcomes = [];
    for (; $this->deliveredCursor < count($held); $this->deliveredCursor++) {
      $outcomes[] = $this->deliver($eventClass, $held[$this->deliveredCursor]['envelope']);
    }
    return $outcomes;
  }

  // ── worker ───────────────────────────────────────────────────────────────

  public function run_worker(array $messages): WorkerRun {
    $errors = $leaks = [];
    foreach ($messages as $message) {
      $error = $leak = null;
      try {
        $message();
      } catch (\Throwable $e) {
        $error = $e;
      } finally {
        try {
          RuntimeReset::between_messages();
        } catch (RuntimeLeakDetected $l) {
          $leak = $l;
        }
      }
      $errors[] = $error;
      $leaks[] = $leak;
    }
    return new WorkerRun($errors, $leaks);
  }

  public function runner_transients(): ?array {
    $runner = $this->worker(1)->runner();
    // The runner's one per-message transient (register 3.9), read without
    // widening its API.
    return ['resume_argument' => (fn () => $this->resume_argument)->call($runner)];
  }

  // ── optional seams (CR-CC-1) ─────────────────────────────────────────────

  public function fail_next_audit_close(string $reason): void {
    $this->audit_port->fail_next_close($reason);
  }

  public function signals(): array {
    return array_map(static fn (array $s) => $s['event'], $this->signals->emitted);
  }

  // ── RelayRace (CR-W3CP-2), StatementErrors (CR-W3CP-3) ──────────────────

  public function race_next_relay(callable $competitor): void {
    $this->race = \Closure::fromCallable($competitor);
  }

  public function fail_statement(): void {
    if (!$this->boundary->is_active()) {
      throw new \LogicException('fail_statement() runs inside the open transaction');
    }
    // mem has no aborted-transaction state, like MySQL: the statement fails
    // and the transaction stays usable.
    throw new \RuntimeException('duplicate key: scenario row (simulated statement error)');
  }

  // ── ProcessHost (CR-W3CP-1) ──────────────────────────────────────────────

  public function wire_processes(array $starts, array $awaits): void {
    foreach ($starts as $pair) {
      $this->starts[] = $pair;
    }
    foreach ($awaits as $class) {
      $this->awaits[] = $class;
    }
    foreach ($this->workers as $worker) {
      $this->wire($worker->runner(), $starts, $awaits);
    }
  }

  public function worker(int $n = 1): ProcessWorker {
    if ($n < 1) {
      throw new \InvalidArgumentException("No worker $n");
    }
    return $this->workers[$n] ??= $n === 1
      ? $this->build_worker($this->subscriptions, $this->lock)
      : $this->build_worker(new SubscriptionRegistry(), new ReentrantProcessLock($this->raw_lock, $this->logger));
  }

  public function process_store(): IProcessStore {
    return $this->process_store;
  }

  public function wakeups(): IWakeupScheduler {
    return $this->wakeups;
  }

  public function operator_view(): IOperatorView {
    return new PortOperatorView($this->config, $this->outbox, $this->process_store, $this->clock, [$this->ledger, $this->wakeups]);
  }

  public function consumer_prefix(): string {
    return $this->config->prefix();
  }

  public function lock_key(int $processId): LockKey {
    return new LockKey($this->config->prefix(), '', $processId);
  }

  public function process_row(int $id): ?ProcessRow {
    try {
      $p = $this->process_store->find($id);
    } catch (QuarantinedProcess) {
      return null;
    }
    if ($p === null) {
      return null;
    }
    return new ProcessRow(
      $id,
      get_class($p),
      (string) $this->process_store->status_of($id),
      $p->current_step_index(),
      (int) $this->process_store->version_of($id),
      $this->process_store->ignition_key_of($id),
      $p->ignited_by_event_id(),
    );
  }

  public function process_ids(?string $processClass = null): array {
    $ids = [];
    // mem ids are sequential from 1 and rows are never deleted
    for ($id = 1; $id <= $this->process_store->count(); $id++) {
      $row = $this->process_row($id);
      if ($row !== null && ($processClass === null || $row->process_class === $processClass)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  public function live_intents(): array {
    return $this->wakeups->pending();
  }

  public function hold_lock_elsewhere(int $processId): void {
    $this->raw_lock->hold_elsewhere($this->lock_key($processId));
  }

  public function release_lock_elsewhere(int $processId): void {
    $this->raw_lock->release_elsewhere($this->lock_key($processId));
  }

  public function fail_next_lock(string $reason): void {
    $this->raw_lock->fail_next_acquire($reason);
  }

  public function lock_acquisitions(): int {
    return $this->raw_lock->acquisitions();
  }

  public function before_next_lock(callable $fn): void {
    $this->interleaving->before_next_acquire(static function () use ($fn): void {
      $fn();
    });
  }

  public function fail_next_handoff(string $reason): void {
    $this->wake_faults->fail_next($reason);
  }

  // ── EffectHost (CR-W4C4-2) ───────────────────────────────────────────────

  public function effect_journal(): IEffectJournal {
    return $this->effect_journal;
  }

  public function effect_bus(array $handlers): CommandBus {
    return $this->bus(
      [RecordEffect::class => static fn (RecordEffect $r): EffectResult => $r->apply()] + $handlers,
      new BusOptions(),
      new EffectMiddleware($this->effect_journal, $this->boundary),
    );
  }

  // ── WorkflowHost (CR-W4C4-4) ─────────────────────────────────────────────

  public function ignition_ledger(): IWorkflowIgnitionLedger {
    return $this->ignitions;
  }

  public function workflows(): IBehaviourWorkflowRepository {
    return $this->workflows;
  }

  public function igniter(): WorkflowIgniter {
    return new WorkflowIgniter($this->ignitions, $this->boundary, $this->logger, $this->clock);
  }

  // ── ProcessDecodeFaults (CR-W4C4-3) ──────────────────────────────────────

  public function forget_class(int $processId, string $missingClass): void {
    $this->process_store->corrupt_class($processId, $missingClass);
  }

  public function stored_status(int $processId): ?string {
    return $this->process_store->status_of($processId);
  }

  public function quarantine_reason(int $processId): ?string {
    return $this->process_store->quarantine_reason_of($processId);
  }

  // ── mem-only read-back (not HostFixture) ─────────────────────────────────

  public function audit_sink(): InMemoryAuditSink {
    return $this->audit;
  }

  public function fact_observer(): RecordingFactObserver {
    return $this->facts;
  }

  // ── internals ────────────────────────────────────────────────────────────

  protected function build_worker(ISubscriptionRegistry $registry, IProcessLock $lock, ?IDeliveryWorker $delivery = null): MemProcessWorker {
    $runner = new ProcessRunner(
      $this->config, null, $lock, $this->process_store, $this->wakeups, $registry,
      $this->boundary, $this->clock, $this->start_mode, $this->logger,
    );
    $this->wire($runner, $this->starts, $this->awaits);

    return new MemProcessWorker(
      $runner,
      $lock,
      fn (string $eventClass, array $wrapped): DeliveryOutcome => $this->deliver_with($registry, $eventClass, $wrapped),
      fn (int $maxItems): DrainReport => (new Drain(
        $this->relay_processor($this->outbox, $this->outbox_config->batch_size),
        $this->wakeups,
        $this->wake_faults->wrap($runner),
        $delivery,
        $runner,
        $this->clock,
        $this->logger,
      ))->run_once($maxItems),
    );
  }

  /**
   * @param list<array{0: class-string, 1: class-string}> $starts
   * @param list<class-string> $awaits
   */
  protected function wire(ProcessRunner $runner, array $starts, array $awaits): void {
    foreach ($starts as [$process, $event]) {
      $runner->register_start($process, $event);
    }
    foreach ($awaits as $event) {
      $runner->register_event($event);
    }
  }

  protected function deliver_with(ISubscriptionRegistry $registry, string $eventClass, array $wrapped): DeliveryOutcome {
    return (new IntegrationDelivery($registry, $this->ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger))
      ->deliver($eventClass, $wrapped);
  }

  protected function relay_processor(IOutboxStore $store, int $limit): OutboxProcessor {
    return new OutboxProcessor(
      $this->config,
      null,
      OutboxConfig::from_array(['batch_size' => $limit] + get_object_vars($this->outbox_config)),
      null,
      null,
      $this->logger,
      $this->clock,
      $store,
      $this->transport,
      $this->boundary,
    );
  }

  /** @return list<InMemoryTransactional> every enlisted participant except the transport */
  protected function competitor_participants(): array {
    return [$this->outbox, $this->ledger, $this->rows, $this->process_store, $this->wakeups];
  }

  /** @return list<array{event_id: string, envelope: array, due_at: \DateTimeImmutable, ref: ?string}> submissions the transport accepted with a reference */
  protected function held(): array {
    return array_values(array_filter($this->transport->submissions, static fn (array $s) => $s['ref'] !== null));
  }

  private function resetStatics(): void {
    RuntimeReset::forget_for_tests();
    HostDefaults::reset_for_tests();
    Correlation::reset();
    Reactions::reset();
  }
}
