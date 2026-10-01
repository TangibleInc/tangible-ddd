<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\EffectHost;
use TangibleDDD\Conformance\Fixtures\Codec\BlobAttached;
use TangibleDDD\Conformance\Fixtures\Process\ChildPurged;
use TangibleDDD\Conformance\Fixtures\Process\JobFinished;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\WidgetOrdered;
use TangibleDDD\Conformance\Fixtures\Process\WidgetPacked;
use TangibleDDD\Conformance\Fixtures\Process\WidgetScrapped;
use TangibleDDD\Conformance\ProcessDecodeFaults;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\Fixtures\WidgetShipped;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\FreshRun;
use TangibleDDD\Conformance\HostFixture;
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
use TangibleDDD\Conformance\Support\FaultInjectingAuditSink;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\Support\RecordingLogger;
use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Conformance\Support\WakeHandoffFaults;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\ConformanceDatabase;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\FaultInjectingTransport;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\FreshProcessRunner;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\LockProbe;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\OffsetClock;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PdoProcessWorker;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PdoScenarioRows;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\RoutedOutboxStore;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\ScenarioConnection;
use TangibleDDD\Defaults\Pdo\FactClassRecordingEventBus;
use TangibleDDD\Defaults\Pdo\MySqlNamedLock;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Defaults\Pdo\PdoDeliveryWorker;
use TangibleDDD\Defaults\Pdo\PdoEffectJournal;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoOperatorView;
use TangibleDDD\Defaults\Pdo\PdoOutboxAdministration;
use TangibleDDD\Defaults\Pdo\PdoOutboxStore;
use TangibleDDD\Defaults\Pdo\PdoPauseStore;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Defaults\Pdo\SchemaCheck;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\RecordEffect;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The pdo host of the shared conformance scenarios (register section 4,
 * section 8 waves 3-4): ddd-core's Defaults/Pdo adapters on a real MySQL 8.
 * Wave 4 adds the EffectHost seam (EffectMiddleware over PdoEffectJournal,
 * where compose() puts it) and ProcessDecodeFaults (the `process_class`
 * and `quarantine_reason` columns); the audit policy is compose()'s
 * fallback, AttributeAuditPolicy.
 *
 * - Fresh schema per test: set_up() creates a database of its own
 *   (ScenarioContext::unique_name('pdo', 'ddd_w3_conf')), applies
 *   schema/mysql8 with the consumer's table prefix the way a host would
 *   (SchemaSql, then SchemaCheck), and the scenario table. tear_down() kills
 *   every connection the test opened and drops the database. Nothing is
 *   wrapped in a per-test transaction.
 * - One prepare mode per fixture: ATTR_EMULATE_PREPARES false (Native) or
 *   true (Emulated), on every connection, the fresh processes included.
 * - Composition: the in-process ports and pipeline are DurableRuntime::compose()'s
 *   own classes in its own order, built by hand so the fault seams can sit
 *   where a scenario needs them (as the sf fixture does with the bundle):
 *   the command bus Correlation → Transaction (PdoTransactionBoundary,
 *   Reject) → DomainEventsPublish (OrderedListenerDispatcher +
 *   FactClassRecordingEventBus over OutboxIntegrationEventBus) → the
 *   scenario's handler map; the relay step OutboxProcessor over
 *   PdoOutboxStore and PdoJobStore (submit + accept in one transaction);
 *   ProcessRunner on PdoProcessStore, ReentrantProcessLock over
 *   MySqlNamedLock and PdoJobStore, with the start mode read from
 *   HostDefaults as compose() reads it (the fixture provides InBand); a
 *   core Drain with PdoDeliveryWorker. The fresh processes (FreshProcesses) boot through
 *   DurableRuntime::compose() itself (bin/fresh.php).
 * - Faults live below the adapters: COMMIT failure and the GET_LOCK seams
 *   in ScenarioConnection, transport faults in FaultInjectingTransport, the
 *   wake hand-off in WakeHandoffFaults, the audit close in
 *   FaultInjectingAuditSink. Audit goes to an in-memory sink (pdo has no
 *   audit table; compose() wires none).
 * - Clock: OffsetClock (system time + the advance_clock() offset); a fresh
 *   process gets the offset as DDD_CLOCK_OFFSET (EnvOffsetClock).
 * - Workers: worker 1 is the fixture connection; worker n > 1 opens its own
 *   connection (a second MySQL session) with its own adapter set, runner,
 *   ReentrantProcessLock and registry, over the same database.
 */
final class PdoHostFixture implements HostFixture, AuditSinkFaults, RecordsSignals, ProcessHost, FreshProcesses, RelayRace, StatementErrors, EffectHost, ProcessDecodeFaults {

  public const CONSUMER_VERSION = '0.7.0-conformance';

  /** @var list<class-string<IIntegrationEvent>> the conformance facts a drain's delivery stage may hydrate by name */
  public const FACT_CLASSES = [
    WidgetRegistered::class, WidgetShipped::class, WidgetOrdered::class, WidgetPacked::class, PartArrived::class,
    // wave 4: D6 and the D3 answers / cancellations
    BlobAttached::class, JobFinished::class, ChildPurged::class, WidgetScrapped::class,
  ];

  private string $database;
  private string $prefix;
  private string $tablePrefix;

  /** @var list<int> server connection ids this test opened (killed in tear_down) */
  private array $connectionIds = [];

  private bool $ready = false;

  private ConformanceConfig $config;
  private OffsetClock $clock;
  private RecordingLogger $logger;
  private RecordingSignalDispatcher $signals;
  private LockProbe $probe;
  private ScenarioConnection $db;
  private PdoTransactionBoundary $boundary;
  private PdoPauseStore $pauses;
  private PdoOutboxStore $outboxStore;
  private RoutedOutboxStore $outbox;
  private RecordingOutboxStore $relayStore;
  private PdoOutboxAdministration $administration;
  private PdoJobStore $jobs;
  private FaultInjectingTransport $transport;
  private PdoDeliveryLedger $ledger;
  private PdoProcessStore $processStore;
  private ReentrantProcessLock $lock;
  private SubscriptionRegistry $subscriptions;
  private EventsUnitOfWork $events;
  private PdoScenarioRows $rows;
  private OrderedListenerDispatcher $dispatcher;
  private InMemoryAuditSink $audit;
  private FaultInjectingAuditSink $auditPort;
  private OutboxConfig $outboxConfig;
  private WakeHandoffFaults $wakeFaults;
  private PdoEffectJournal $effectJournal;

  /** A session that is neither worker: holds locks "elsewhere", runs RelayRace's competitor. */
  private ?\PDO $side = null;

  /** @var array<int, PdoProcessWorker> */
  private array $workers = [];

  /** @var list<array{0: class-string, 1: class-string}> */
  private array $starts = [];

  /** @var list<class-string> */
  private array $awaits = [];

  /** @var array<int, array{event_id: string, due_at: \DateTimeImmutable, envelope: array}> deliver jobs seen, by job id (a drain deletes delivered ones) */
  private array $seenJobs = [];

  /** @var array<int, true> job ids deliver_transported() already delivered */
  private array $deliveredJobs = [];

  private bool $crashAfterSubmit = false;

  private ?\Closure $race = null;

  /** @var list<string> diagnostics the runtime classes logged */
  public array $logs = [];

  /**
   * @param StartMode $startMode provided to HostDefaults, which is how a
   *   raw-PHP host chooses it for DurableRuntime::compose() (whose own
   *   default is Deferred). The pdo host runs in-band (the ProcessHost
   *   contract: "in-band on mem, pdo and wp"), here and in every fresh
   *   process; the scenarios are start-mode neutral.
   */
  public function __construct(
    private readonly bool $emulatePrepares,
    private readonly StartMode $startMode = StartMode::InBand,
  ) {}

  public function name(): string {
    return 'pdo';
  }

  public function emulatesPrepares(): bool {
    return $this->emulatePrepares;
  }

  public function set_up(ScenarioContext $context): void {
    $this->resetStatics();

    $this->database = $context->unique_name('pdo', 'ddd_w3_conf');
    $this->prefix = 'pc' . substr(sha1($this->database), 0, 10);
    $this->tablePrefix = $this->prefix . '_';
    ConformanceDatabase::create($this->database);

    $this->config = new ConformanceConfig($this->prefix, self::CONSUMER_VERSION);
    $this->clock = new OffsetClock();
    $this->logger = new RecordingLogger(fn (string $m) => $this->logs[] = $m);
    $this->signals = new RecordingSignalDispatcher();
    $this->probe = new LockProbe();
    $this->outboxConfig = new OutboxConfig();
    $this->db = $this->openConnection(primary: true);
    $this->ready = true;

    foreach (SchemaSql::statements($this->tablePrefix) as $statement) {
      $this->db->execute($statement);
    }
    $this->db->execute(PdoScenarioRows::createSql($this->tablePrefix));
    (new SchemaCheck($this->db, $this->tablePrefix))->assert();

    $this->boundary = new PdoTransactionBoundary($this->db, NestedPolicy::Reject, $this->logger);
    $this->pauses = new PdoPauseStore($this->db, $this->tablePrefix, $this->clock);
    $this->outboxStore = new PdoOutboxStore($this->db, $this->pauses, $this->tablePrefix, $this->clock, $this->logger);
    $this->outbox = new RoutedOutboxStore($this->outboxStore);
    $this->relayStore = new RecordingOutboxStore($this->outbox);
    $this->administration = new PdoOutboxAdministration($this->db, $this->tablePrefix, $this->clock);
    $this->jobs = new PdoJobStore($this->db, $this->prefix, $this->tablePrefix, $this->clock, $this->logger);
    $this->transport = new FaultInjectingTransport($this->jobs);
    $this->ledger = new PdoDeliveryLedger($this->db, $this->tablePrefix, $this->clock);
    $this->processStore = new PdoProcessStore($this->db, $this->tablePrefix, $this->clock, logger: $this->logger);
    $this->lock = new ReentrantProcessLock(new MySqlNamedLock($this->db, $this->logger), $this->logger);
    $this->subscriptions = new SubscriptionRegistry();
    $this->events = new EventsUnitOfWork();
    $this->rows = new PdoScenarioRows($this->db, $this->tablePrefix);
    $this->dispatcher = new OrderedListenerDispatcher();
    $this->audit = new InMemoryAuditSink();
    $this->auditPort = new FaultInjectingAuditSink($this->audit);
    $this->wakeFaults = new WakeHandoffFaults();
    $this->effectJournal = new PdoEffectJournal($this->db, $this->tablePrefix, $this->clock);

    HostDefaults::provide(LoggerInterface::class, $this->logger);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);
    HostDefaults::provide(IClock::class, $this->clock);
    HostDefaults::provide(StartMode::class, $this->startMode);

    RuntimeReset::register('conformance.pdo.events', fn () => $this->events->reset());
    RuntimeReset::guard($this->lock);

    // Step commands commit their effect row on this connection.
    ProcessJournal::bind($this->rows, $this->boundary);
  }

  public function tear_down(): void {
    ProcessJournal::bind(null);
    $this->resetStatics();
    if ($this->ready) {
      $this->ready = false;
      $this->workers = [];
      $this->side = null;
      ConformanceDatabase::drop($this->database, $this->connectionIds);
    }
  }

  // ── time ─────────────────────────────────────────────────────────────────

  public function clock(): IClock {
    return $this->clock;
  }

  public function advance_clock(int $seconds): void {
    $this->clock->advance($seconds);
  }

  // ── ports ────────────────────────────────────────────────────────────────

  public function boundary(): ITransactionBoundary {
    return $this->boundary;
  }

  public function outbox(): IOutboxStore {
    return $this->outbox;
  }

  public function outbox_admin(): IOutboxAdministration {
    return $this->administration;
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
   * compose()'s bus, by hand: Correlation → [Effect] → Transaction →
   * DomainEventsPublish → the handler map. The audit policy is compose()'s
   * fallback, AttributeAuditPolicy (CR-W4CE-3; compose() passes none and
   * CorrelationMiddleware falls back to it), or none at all for
   * BusOptions::$audit = false.
   *
   * @param array<class-string, callable(object): mixed> $handlers
   */
  private function bus(array $handlers, BusOptions $options, ?Middleware $effects): CommandBus {
    $policy = $options->audit ? new AttributeAuditPolicy() : new class implements IAuditPolicy {
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
        $this->auditPort,
        new FixedActorProvider(),
        $policy,
        new PhpEnvironmentProvider(['host' => 'pdo']),
      ),
      $effects,
      new TransactionalCommandMiddleware($options->boundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, new FactClassRecordingEventBus(
          new OutboxIntegrationEventBus(null, $this->config, null, $this->clock, $this->outboxStore, $this->outboxConfig),
          $this->outboxStore,
        )),
      ),
      new HandlerMapMiddleware($handlers),
    ]));
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function fail_next_commit(string $reason): void {
    $this->db->failNextCommit($reason);
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

  /** One step of the core OutboxProcessor over PdoOutboxStore + PdoJobStore, batch size $limit. */
  public function relay_once(int $limit = 50): RelayReport {
    $processor = $this->relayProcessor($this->relayStore, $this->transport, $this->boundary, $limit);

    if ($this->crashAfterSubmit) {
      $this->crashAfterSubmit = false;
      $processor->between_submit_and_accept(static function ($claim): void {
        throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
      });
    } elseif ($this->race !== null) {
      $competitor = $this->race;
      $this->race = null;
      $processor->between_submit_and_accept(function () use ($competitor): void {
        // Another relay process: the same table through another session,
        // autocommit, while this step's transaction is still open.
        $other = new PdoOutboxStore(
          new PdoConnection($this->side()),
          new PdoPauseStore(new PdoConnection($this->side()), $this->tablePrefix, $this->clock),
          $this->tablePrefix,
          $this->clock,
          $this->logger,
        );
        $this->outbox->asOtherConnection($other, $competitor);
      });
    }

    $this->relayStore->reset();
    $processor->process_batch();
    return $this->relayStore->report();
  }

  public function reject_next_submission(?\Throwable $e = null): void {
    $this->transport->rejectNext($e);
  }

  public function accept_next_without_ref(): void {
    $this->transport->noRefNext();
  }

  public function crash_next_relay(): void {
    $this->crashAfterSubmit = true;
  }

  public function transported(): array {
    return array_values(array_map(
      static fn (array $j) => new TransportedFact($j['event_id'], $j['due_at']),
      $this->deliverJobs(),
    ));
  }

  public function seed_legacy_fact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
    // No 0.6 schema on pdo: the port record carries the absolute time.
    $eventId = Uuid::v4();
    $this->outboxStore->append_fact(new OutboxRecord(
      event_id: $eventId,
      event_type: $fact::name(),
      integration_action: $fact::integration_action(),
      correlation_id: Uuid::v4(),
      sequence: 1,
      command_id: null,
      payload: $fact->integration_payload(),
      due_at: $scheduledAt,
      max_attempts: $this->outboxConfig->max_attempts,
    ), get_class($fact));
    return $eventId;
  }

  // ── delivery ─────────────────────────────────────────────────────────────

  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    return $this->worker(1)->deliver($eventClass, $wrapped);
  }

  public function deliver_transported(string $eventClass): array {
    $outcomes = [];
    foreach ($this->deliverJobs() as $id => $job) {
      if (isset($this->deliveredJobs[$id])) {
        continue;
      }
      $this->deliveredJobs[$id] = true;
      $outcomes[] = $this->deliver($eventClass, $job['envelope']);
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
    // The runner's one per-message transient (register 3.9), read without widening its API.
    return ['resume_argument' => (fn () => $this->resume_argument)->call($runner)];
  }

  // ── AuditSinkFaults, RecordsSignals ──────────────────────────────────────

  public function fail_next_audit_close(string $reason): void {
    $this->auditPort->fail_next_close($reason);
  }

  public function signals(): array {
    return array_map(static fn (array $s) => $s['event'], $this->signals->emitted);
  }

  // ── RelayRace, StatementErrors ───────────────────────────────────────────

  public function race_next_relay(callable $competitor): void {
    $this->race = \Closure::fromCallable($competitor);
  }

  public function fail_statement(): void {
    if (!$this->db->in_transaction()) {
      throw new \LogicException('fail_statement() runs inside the open transaction');
    }
    // MySQL rejects the statement (1048, NOT NULL) and keeps the transaction usable.
    $this->db->execute("INSERT INTO `{$this->tablePrefix}scenario_rows` (id, value) VALUES (?, NULL)", ['statement-error']);
  }

  // ── EffectHost (CR-W4C4-2, D1) ───────────────────────────────────────────

  /** compose()'s journal: PdoEffectJournal on the fixture connection, so a repair's invalidate() rolls back with it. */
  public function effect_journal(): IEffectJournal {
    return $this->effectJournal;
  }

  /**
   * The command bus with EffectMiddleware where compose() puts it
   * (Correlation → Effect → Transaction, CR-PDO4-4). compose() reaches
   * RecordEffect through its SelfExecuting stage; the handler map here
   * routes it to RecordEffect::apply().
   */
  public function effect_bus(array $handlers): CommandBus {
    return $this->bus(
      [RecordEffect::class => static fn (RecordEffect $r): EffectResult => $r->apply()] + $handlers,
      new BusOptions(),
      new EffectMiddleware($this->effectJournal, $this->boundary),
    );
  }

  // ── ProcessDecodeFaults (CR-W4C4-3) ──────────────────────────────────────

  /** The class lives in `process_class` only (business_data holds constructor arguments, no class). */
  public function forget_class(int $processId, string $missingClass): void {
    $this->db->execute(
      "UPDATE `{$this->tablePrefix}ddd_processes` SET process_class = ? WHERE id = ?",
      [$missingClass, $processId],
    );
  }

  public function stored_status(int $processId): ?string {
    $row = $this->db->fetch_one("SELECT status FROM `{$this->tablePrefix}ddd_processes` WHERE id = ?", [$processId]);
    return $row === null ? null : (string) $row['status'];
  }

  public function quarantine_reason(int $processId): ?string {
    $row = $this->db->fetch_one("SELECT quarantine_reason FROM `{$this->tablePrefix}ddd_processes` WHERE id = ?", [$processId]);
    return $row === null || $row['quarantine_reason'] === null ? null : (string) $row['quarantine_reason'];
  }

  // ── ProcessHost ──────────────────────────────────────────────────────────

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
    return $this->workers[$n] ??= $n === 1 ? $this->buildPrimaryWorker() : $this->buildWorker();
  }

  public function process_store(): IProcessStore {
    return $this->processStore;
  }

  public function wakeups(): IWakeupScheduler {
    return $this->jobs;
  }

  public function operator_view(): IOperatorView {
    return new PdoOperatorView($this->db, $this->prefix, $this->tablePrefix, $this->clock);
  }

  public function consumer_prefix(): string {
    return $this->prefix;
  }

  public function lock_key(int $processId): LockKey {
    return new LockKey($this->prefix, '', $processId);
  }

  public function process_row(int $id): ?ProcessRow {
    $row = $this->db->fetch_one(
      "SELECT id, process_class, status, step_index, version, ignition_key, ignited_by_event_id
         FROM `{$this->tablePrefix}ddd_processes` WHERE id = ?",
      [$id]
    );
    if ($row === null) {
      return null;
    }
    return new ProcessRow(
      (int) $row['id'],
      (string) $row['process_class'],
      (string) $row['status'],
      (int) $row['step_index'],
      (int) $row['version'],
      $row['ignition_key'] === null ? null : (string) $row['ignition_key'],
      $row['ignited_by_event_id'] === null ? null : (string) $row['ignited_by_event_id'],
    );
  }

  public function process_ids(?string $processClass = null): array {
    $rows = $processClass === null
      ? $this->db->fetch_all("SELECT id FROM `{$this->tablePrefix}ddd_processes` ORDER BY id")
      : $this->db->fetch_all("SELECT id FROM `{$this->tablePrefix}ddd_processes` WHERE process_class = ? ORDER BY id", [$processClass]);
    return array_map(static fn (array $r) => (int) $r['id'], $rows);
  }

  public function live_intents(): array {
    $rows = $this->db->fetch_all(
      "SELECT kind, consumer, process_id, step_index, expected_status, due_at, idempotency_key
         FROM `{$this->tablePrefix}ddd_jobs` WHERE kind <> 'deliver' ORDER BY id"
    );
    return array_map(static fn (array $r) => new WakeupIntent(
      WakeKind::from((string) $r['kind']),
      (string) $r['consumer'],
      $r['process_id'] === null ? null : (int) $r['process_id'],
      $r['step_index'] === null ? null : (int) $r['step_index'],
      $r['expected_status'] === null ? null : (string) $r['expected_status'],
      self::utc((string) $r['due_at']),
      (string) $r['idempotency_key'],
    ), $rows);
  }

  public function hold_lock_elsewhere(int $processId): void {
    $statement = $this->side()->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$this->lock_key($processId)->mysql_name()]);
    if ((string) $statement->fetchColumn() !== '1') {
      throw new \LogicException("the side session could not take process #$processId's lock");
    }
  }

  public function release_lock_elsewhere(int $processId): void {
    $statement = $this->side()->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$this->lock_key($processId)->mysql_name()]);
  }

  public function fail_next_lock(string $reason): void {
    $this->probe->failNext($reason);
  }

  public function lock_acquisitions(): int {
    return $this->probe->acquisitions;
  }

  public function before_next_lock(callable $fn): void {
    $this->probe->beforeNext($fn);
  }

  public function fail_next_handoff(string $reason): void {
    $this->wakeFaults->fail_next($reason);
  }

  // ── FreshProcesses ───────────────────────────────────────────────────────

  public function publish_fresh(DomainEvent&IIntegrationEvent $fact, bool $killAfterCommit): string {
    $out = $this->fresh()->run('publish', ['fact' => $fact, 'kill' => $killAfterCommit]);
    return (string) ($out['eventId'] ?? throw new \LogicException('the fresh process published nothing: ' . json_encode($out)));
  }

  public function drain_fresh(): FreshRun {
    return $this->fresh()->freshRun($this->fresh()->run('drain', []));
  }

  public function deliver_fresh(string $eventClass, array $wrapped): FreshRun {
    return $this->fresh()->freshRun($this->fresh()->run('deliver', ['eventClass' => $eventClass, 'wrapped' => $wrapped]));
  }

  public function start_fresh(LongProcess $process, ?string $dieAfterCommand = null): FreshRun {
    return $this->fresh()->freshRun($this->fresh()->run('start', ['process' => $process, 'dieAfterCommand' => $dieAfterCommand]));
  }

  // ── internals ────────────────────────────────────────────────────────────

  private function fresh(): FreshProcessRunner {
    return new FreshProcessRunner(
      $this->database,
      $this->prefix,
      self::CONSUMER_VERSION,
      $this->emulatePrepares,
      $this->clock->offsetSeconds(),
    );
  }

  private function openConnection(bool $primary): ScenarioConnection {
    $pdo = ConformanceDatabase::connect($this->database, $this->emulatePrepares);
    $this->connectionIds[] = ConformanceDatabase::connectionId($pdo);
    return new ScenarioConnection(new PdoConnection($pdo), $this->probe, $primary);
  }

  private function side(): \PDO {
    if ($this->side === null) {
      $this->side = ConformanceDatabase::connect($this->database, $this->emulatePrepares);
      $this->connectionIds[] = ConformanceDatabase::connectionId($this->side);
    }
    return $this->side;
  }

  /** Worker 1: the fixture's own connection, ports, lock and subscriptions. */
  private function buildPrimaryWorker(): PdoProcessWorker {
    return $this->composeWorker(
      $this->subscriptions, $this->lock, $this->boundary, $this->processStore, $this->jobs,
      $this->outboxStore, $this->transport, $this->ledger,
    );
  }

  /** Worker n > 1: a second MySQL session with its own adapter set over the same database. */
  private function buildWorker(): PdoProcessWorker {
    $db = $this->openConnection(primary: false);
    $jobs = new PdoJobStore($db, $this->prefix, $this->tablePrefix, $this->clock, $this->logger);
    return $this->composeWorker(
      new SubscriptionRegistry(),
      new ReentrantProcessLock(new MySqlNamedLock($db, $this->logger), $this->logger),
      new PdoTransactionBoundary($db, NestedPolicy::Reject, $this->logger),
      new PdoProcessStore($db, $this->tablePrefix, $this->clock, logger: $this->logger),
      $jobs,
      new PdoOutboxStore($db, new PdoPauseStore($db, $this->tablePrefix, $this->clock), $this->tablePrefix, $this->clock, $this->logger),
      $jobs,
      new PdoDeliveryLedger($db, $this->tablePrefix, $this->clock),
    );
  }

  /** What DurableRuntime::compose() builds for processes and the drain, on one connection's ports. */
  private function composeWorker(
    ISubscriptionRegistry $registry,
    IProcessLock $lock,
    ITransactionBoundary $boundary,
    IProcessStore $store,
    PdoJobStore $jobs,
    IOutboxStore $outbox,
    ITransport $transport,
    IDeliveryLedger $ledger,
  ): PdoProcessWorker {
    $startMode = HostDefaults::get(StartMode::class); // as compose() reads it
    $runner = new ProcessRunner(
      $this->config, null, $lock, $store, $jobs, $registry, $boundary, $this->clock,
      $startMode instanceof StartMode ? $startMode : StartMode::Deferred,
      $this->logger,
    );
    $this->wire($runner, $this->starts, $this->awaits);
    $delivery = new IntegrationDelivery($registry, $ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger);

    $drain = new Drain(
      relay: $this->relayProcessor($outbox, $transport, $boundary, $this->outboxConfig->batch_size),
      wakeups: $jobs->claiming(WakeKind::Continue, WakeKind::Timeout, WakeKind::ResumeRetry),
      processWakes: $this->wakeFaults->wrap($runner),
      delivery: new PdoDeliveryWorker($jobs, $delivery, self::factClassMap(), logger: $this->logger),
      stranded: $runner,
      clock: $this->clock,
      logger: $this->logger,
    );
    return new PdoProcessWorker($runner, $lock, $delivery, $drain);
  }

  /**
   * @param list<array{0: class-string, 1: class-string}> $starts
   * @param list<class-string> $awaits
   */
  private function wire(ProcessRunner $runner, array $starts, array $awaits): void {
    foreach ($starts as [$process, $event]) {
      $runner->register_start($process, $event);
    }
    foreach ($awaits as $event) {
      $runner->register_event($event);
    }
  }

  private function relayProcessor(IOutboxStore $store, ITransport $transport, ITransactionBoundary $boundary, int $limit): OutboxProcessor {
    return new OutboxProcessor(
      $this->config,
      null,
      OutboxConfig::from_array(['batch_size' => $limit] + get_object_vars($this->outboxConfig)),
      null,
      null,
      $this->logger,
      $this->clock,
      $store,
      $transport,
      $boundary,
    );
  }

  /** @return array<int, array{event_id: string, due_at: \DateTimeImmutable, envelope: array}> every deliver job seen so far, by job id */
  private function deliverJobs(): array {
    $rows = $this->db->fetch_all(
      "SELECT id, event_id, due_at, envelope FROM `{$this->tablePrefix}ddd_jobs` WHERE kind = 'deliver' ORDER BY id"
    );
    foreach ($rows as $r) {
      $this->seenJobs[(int) $r['id']] ??= [
        'event_id' => (string) $r['event_id'],
        'due_at' => self::utc((string) $r['due_at']),
        'envelope' => (array) json_decode((string) $r['envelope'], true, 512, JSON_THROW_ON_ERROR),
      ];
    }
    ksort($this->seenJobs, SORT_NUMERIC);
    return $this->seenJobs;
  }

  /** @return array<string, class-string<IIntegrationEvent>> */
  public static function factClassMap(): array {
    $map = [];
    foreach (self::FACT_CLASSES as $class) {
      $map[$class::name()] = $class;
    }
    return $map;
  }

  private static function utc(string $db): \DateTimeImmutable {
    return new \DateTimeImmutable($db, new \DateTimeZone('UTC'));
  }

  private function resetStatics(): void {
    RuntimeReset::forget_for_tests();
    HostDefaults::reset_for_tests();
    ConsumerRegistry::reset();
    Correlation::reset();
    Reactions::reset();
  }
}
