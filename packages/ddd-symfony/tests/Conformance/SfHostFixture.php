<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use League\Tactician\CommandBus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport as MessengerInMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
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
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\CrossConsumerHost;
use TangibleDDD\Conformance\EffectStateHost;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\FreshRun;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\PostCommitWakeups;
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
use TangibleDDD\Conformance\Support\FaultInjectingAuditSink;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\Support\InterleavingProcessLock;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WebRequests;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Conformance\WorkflowHost;
use TangibleDDD\Conformance\WorkItemHost;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\RecordEffect;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
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
use TangibleDDD\Runtime\Process\StrandedScanReport;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Messenger\FactAudience;
use TangibleDDD\Symfony\Messenger\FactDeliveryIncomplete;
use TangibleDDD\Symfony\Messenger\IntegrationFactHandler;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Ops\DbalLedgerOperatorSource;
use TangibleDDD\Symfony\Ops\DbalWakeupOperatorSource;
use TangibleDDD\Symfony\Persistence\DbalBehaviourWorkflowRepository;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalEffectJournal;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalParkingScheduler;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Symfony\Persistence\DbalWorkItemRepository;
use TangibleDDD\Symfony\Persistence\ParkedFacts;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Persistence\PostgresSchema;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\Actor\SecurityUserActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SymfonyActorProvider;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Runtime\DddRuntimeReset;
use TangibleDDD\Symfony\Runtime\DddSignal;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Runtime\SymfonySignalDispatcher;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresListenWaiter;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresNotifyRelayWakeup;
use TangibleDDD\Symfony\Runtime\Wakeup\ProcessRunnerWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelay;
use TangibleDDD\Symfony\Tests\Conformance\Support\CountingProcessLock;
use TangibleDDD\Symfony\Tests\Conformance\Support\DbalScenarioRows;
use TangibleDDD\Symfony\Tests\Conformance\Support\FaultInjectingSender;
use TangibleDDD\Symfony\Tests\Conformance\Support\LeakRecordingLogger;
use TangibleDDD\Symfony\Tests\Conformance\Support\LockCounter;
use TangibleDDD\Symfony\Tests\Conformance\Support\RaceableConnection;
use TangibleDDD\Symfony\Tests\Conformance\Support\ScenarioSchemaMiddleware;
use TangibleDDD\Symfony\Tests\Conformance\Support\SendFaults;
use TangibleDDD\Symfony\Tests\Conformance\Support\SfProcessWorker;
use TangibleDDD\Symfony\Tests\Conformance\Support\SfWorkerPorts;
use TangibleDDD\Symfony\Tests\Conformance\Support\StatementFaults;
use TangibleDDD\Symfony\Tests\Conformance\Support\SuppressibleRelayWakeup;
use TangibleDDD\Symfony\Tests\Conformance\Support\WorkerTask;
use TangibleDDD\Symfony\Tests\Kernel\App\TestKernel;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;
use TangibleDDD\Testing\InMemoryAuditSink;

/**
 * The sf host of the shared conformance scenarios (register section 4):
 * Postgres 16 through DBAL 4, Messenger's Doctrine transport, and the
 * ddd-symfony classes the bundle wires (config/services.php), composed by
 * hand so each scenario can pick its handlers, listeners and bus options.
 *
 * - Fresh schema per test: set_up() creates a Postgres schema named
 *   ScenarioContext::unique_name('sf'), applies schema/postgres, the Doctrine
 *   transport table and the scenario table, and pins every physical
 *   connection to it (search_path, ScenarioSchemaMiddleware). tear_down()
 *   drops it. Nothing is wrapped in a per-test transaction.
 * - Worker 1 is ONE DBAL connection: boundary, outbox, administration,
 *   pauses, ledger, scenario rows, process store, intents, the advisory
 *   lock session and both Messenger transports (`ddd_facts`,
 *   `ddd_wakeups`) go through it, so the relay hand-off is the
 *   shared-connection one (submit + accept in one transaction).
 * - Command bus in the bundle's frozen order: the core act bracket
 *   (CorrelationMiddleware), TransactionalCommandMiddleware over
 *   DbalTransactionBoundary (Reject), DomainEventsPublishMiddleware over
 *   OrderedListenerDispatcher and the bundle's integration bus, then the
 *   scenario's handler map. Audit goes to an in-memory sink behind a
 *   fault-injecting decorator (the bundle's default sink is NullAuditSink;
 *   sf has no audit table). Signals go to the bundle's
 *   SymfonySignalDispatcher, read back from DddSignal events.
 * - Relay: Runtime\Relay, the core relay step `ddd:relay` runs.
 * - Delivery: IntegrationFactHandler on a Messenger bus; the Doctrine
 *   transport is consumed by a real Messenger Worker.
 * - Processes (ProcessHost): the core ProcessRunner built by
 *   Factory::process_runner() with the bundle default StartMode::Deferred,
 *   on DbalProcessStore, DbalParkingScheduler (the bundle's
 *   wakeup_scheduler: a contended fact resume is parked with its fact,
 *   AW2; ParkedFacts gives it back at the wake) and the core
 *   ReentrantProcessLock over PostgresAdvisoryProcessLock. A drain
 *   (ProcessWorker::drain_once()) is what the sf workers do in one pass:
 *   `ddd:relay` (outbox step, then WakeupRelay: stranded scan and due
 *   intents projected to `ddd_wakeups`), then `messenger:consume ddd_facts
 *   ddd_wakeups` (IntegrationFactHandler, ProcessWakeupHandler), with
 *   DddRuntimeReset after every message.
 * - Worker n > 1: the same composition on a second DBAL connection (a
 *   second advisory-lock session) over the same schema.
 * - Fresh processes (FreshProcesses): a separate `php` process
 *   (tests/Conformance/bin/fresh-process.php) that attaches to the
 *   test's schema with this same composition (attach()), on the host
 *   clock's current instant.
 * - COMMIT failure: a deferred foreign key violated at COMMIT, so Postgres
 *   itself rejects the COMMIT.
 * - Wave 5: EffectStateHost (the operator view lists UnrecordedEffects over
 *   DbalEffectJournal next to the ledger and wakeup sources), WorkItemHost
 *   (DbalBehaviourWorkflowRepository, DbalWorkItemRepository) and
 *   CrossConsumerHost (a second consumer, OTHER, see there).
 */
final class SfHostFixture implements HostFixture, AuditSinkFaults, RecordsSignals, ProcessHost, FreshProcesses, WebRequests, RelayRace, StatementErrors, ProcessDecodeFaults, EffectStateHost, WorkflowHost, PostCommitWakeups, WorkItemHost, CrossConsumerHost {

  public const CONSUMER = 'sfc';

  /** The second consumer of CrossConsumerHost: its prefix, table prefix and facts transport (queue). */
  public const OTHER = 'sfb';
  private const OTHER_TABLES = 'sfb_';
  private const OTHER_FACTS = 'ddd_facts_sfb';

  /** register 5.3 step 5: the stranded threshold (the bundle default). */
  private const STRANDED_AFTER_SECONDS = 900;

  /** The worker number of the PostCommitWakeups relay worker (its own connection). */
  private const RELAY_WORKER = 9;

  /** `ddd:relay --sleep` of the PostCommitWakeups worker: short, but well above the 1 s wakeup bound. */
  private const RELAY_POLL_SECONDS = 3.0;

  /** tangible_ddd.process.wakeup_lease_seconds default. */
  private const WAKE_LEASE_SECONDS = 300;

  private string $schema;
  private bool $ownsSchema = false;
  private bool $ready = false;
  private StartMode $startMode = StartMode::Deferred;
  private StatementFaults $statementFaults;
  private ScenarioSchemaMiddleware $middleware;
  private RaceableConnection $connection;
  private FrozenClock $clock;
  private LoggerInterface $logger;
  private SymfonyConsumerConfig $consumer;
  private OutboxConfig $outboxConfig;
  private EventsUnitOfWork $events;
  private DbalScenarioRows $rows;
  private OrderedListenerDispatcher $dispatcher;
  private InMemoryAuditSink $audit;
  private FaultInjectingAuditSink $auditPort;
  private ActorContext $actors;
  private DddRuntimeReset $reset;
  private LeakRecordingLogger $leaks;
  private SendFaults $wakeFaults;
  private LockCounter $locks;
  private ?PostgresAdvisoryProcessLock $webLock = null;
  private ?Connection $elsewhere = null;
  private ?DbalEffectJournal $effectJournal = null;
  private ?SuppressibleRelayWakeup $relayWakeup = null;
  private ?PostgresListenWaiter $relayWaiter = null;
  private float $relayIdleSince = 0.0;
  private ?DbalWorkflowIgnitionLedger $workflowLedger = null;
  private ?DbalBehaviourWorkflowRepository $workflowRepository = null;
  private ?WorkflowIgniter $workflowIgniter = null;
  private ?DbalWorkItemRepository $workItems = null;

  /** The other consumer's subscription map: empty at compile time, so it hears a class only once a scenario subscribes. */
  private CompiledSubscriptionRegistry $otherSubscriptions;
  private ?DbalDeliveryLedger $otherLedger = null;

  /** @var array<int, SfWorkerPorts> */
  private array $ports = [];

  /** @var array<int, SfProcessWorker> */
  private array $workers = [];

  /** @var list<array{0: class-string, 1: class-string}> */
  private array $starts = [];

  /** @var list<class-string> */
  private array $awaits = [];

  /** @var list<\TangibleDDD\Application\Infrastructure\IInfrastructureEvent> */
  private array $signals = [];

  /** @var array<string, TransportedFact> messenger id => fact, for messages already consumed (ack deletes the row) */
  private array $consumed = [];

  /** @var list<string> subscriber failures of the last drain's delivery stage */
  private array $lastDeliveryFailures = [];

  /**
   * @param StartMode $startMode the bundle default (Deferred), or InBand
   *   (`tangible_ddd.process.inband_start: true`) for a scenario that
   *   assumes the first step runs inside start()
   */
  public function __construct(StartMode $startMode = StartMode::Deferred) {
    $this->startMode = $startMode;
  }

  public function name(): string {
    return 'sf';
  }

  public function set_up(ScenarioContext $context): void {
    $this->resetStatics();

    $this->schema = $context->unique_name('sf');
    $admin = PostgresDatabase::connect();
    try {
      $admin->executeStatement('CREATE SCHEMA ' . $this->schema);
    } finally {
      $admin->close();
    }
    $this->ownsSchema = true;

    $this->compose(new \DateTimeImmutable('@' . time()), createTables: true);
    ProcessJournal::bind($this->rows, $this->boundary());
  }

  /**
   * The composition of set_up() over an EXISTING schema, for a fresh php
   * process (tests/Conformance/bin/fresh-process.php). Nothing is created,
   * and detach() drops nothing.
   */
  public static function attach(string $schema, \DateTimeImmutable $now, StartMode $startMode = StartMode::Deferred): self {
    $host = new self();
    $host->resetStatics();
    $host->schema = $schema;
    $host->startMode = $startMode;
    $host->compose($now, createTables: false);
    return $host;
  }

  /** End an attach()ed fixture: close its connections, keep the schema. */
  public function detach(): void {
    $this->ownsSchema = false;
    $this->tear_down();
  }

  public function tear_down(): void {
    $this->stop_relay();
    if ($this->ready) {
      $this->ready = false;
      foreach ($this->ports as $w) {
        try {
          $w->lock->release_all();
          if ($w->connection->isTransactionActive()) {
            $w->connection->rollBack();
          }
        } catch (\Throwable) {
        }
        $w->connection->close();
      }
      $this->elsewhere?->close();
      if ($this->ownsSchema) {
        try {
          $admin = PostgresDatabase::connect();
          $admin->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
          $admin->close();
        } catch (\Throwable) {
        }
      }
    }
    $this->ports = [];
    $this->workers = [];
    ProcessJournal::bind(null);
    $this->resetStatics();
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
    return $this->ports[1]->boundary;
  }

  public function outbox(): IOutboxStore {
    return $this->ports[1]->outbox;
  }

  public function outbox_admin(): IOutboxAdministration {
    return $this->ports[1]->administration;
  }

  public function pauses(): IRelayPauseStore {
    return $this->ports[1]->pauses;
  }

  public function transport(): ITransport {
    return $this->ports[1]->transport;
  }

  public function ledger(): IDeliveryLedger {
    return $this->ports[1]->ledger;
  }

  public function subscriptions(): ISubscriptionRegistry {
    return $this->ports[1]->subscriptions;
  }

  public function lock(): IProcessLock {
    return $this->ports[1]->lock;
  }

  public function events(): EventsUnitOfWork {
    return $this->events;
  }

  public function rows(): ScenarioRows {
    return $this->rows;
  }

  // ── command pipeline ─────────────────────────────────────────────────────

  public function command_bus(array $handlers, BusOptions $options = new BusOptions()): CommandBus {
    return $this->bundleBus($handlers, $options, null);
  }

  /**
   * The bundle's command bus (config/services.php): act bracket → [effect] →
   * transaction → domain events → handler map. $effects is the bundle's
   * `tangible_ddd.middleware.effect` (EffectHost only); command_bus() keeps
   * the frozen HostFixture order without it.
   *
   * @param array<class-string, callable(object): mixed> $handlers
   */
  private function bundleBus(array $handlers, BusOptions $options, ?EffectMiddleware $effects): CommandBus {
    // The bundle's default policy (D12); no conformance command carries #[Audit].
    $policy = $options->audit ? new AttributeAuditPolicy() : new class implements IAuditPolicy {
      public function audits(object $command): bool {
        return false;
      }

      public function captures_parameters(object $command): bool {
        return false;
      }
    };

    $w = $this->ports[1];
    return new CommandBus(...array_values(array_filter([
      new CorrelationMiddleware(
        $this->consumer,
        $this->events,
        new Redactor(),
        $this->auditPort,
        new SymfonyActorProvider($this->actors, new SecurityUserActorProvider(null)),
        $policy,
        new PhpEnvironmentProvider(['host' => 'sf']),
      ),
      $effects,
      new TransactionalCommandMiddleware($options->boundary ? $w->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, Factory::integration_bus($w->outbox, $this->clock, $this->consumer, $this->outboxConfig)),
      ),
      new HandlerMapMiddleware($handlers),
    ])));
  }

  // ── EffectHost (CR-W4C4-2) ───────────────────────────────────────────────

  /** The bundle's `tangible_ddd.effect_journal` on worker 1's connection: invalidate() rolls back with its command. */
  public function effect_journal(): DbalEffectJournal {
    return $this->effectJournal ??= new DbalEffectJournal($this->connection, $this->clock);
  }

  // ── PostCommitWakeups (CR-W4C4-5) ────────────────────────────────────────

  /**
   * The `ddd:relay` worker, in steps: its own DBAL connection (worker
   * RELAY_WORKER, a direct session like the production worker's), the
   * bundle's Relay on it, and PostgresListenWaiter for the LISTEN half.
   * The loop rule is RelayCommand's: run a pass; when it claimed nothing,
   * wait on LISTEN for at most the poll interval, then run the next pass.
   * The poll interval is measured from the moment the worker went idle.
   * (A separate `ddd:relay` php process is covered by
   * tests/Kernel/PostCommitWakeupTest and PostCommitPollFallbackTest.)
   */
  public function start_relay(): void {
    $this->worker(self::RELAY_WORKER);
    $this->relayWaiter = new PostgresListenWaiter($this->ports[self::RELAY_WORKER]->connection, self::CONSUMER, $this->logger);
    $this->relayPassUntilIdle();
    $this->relayWaiter->listen(); // idle and listening before the scenario commits
  }

  public function relay_until(string $eventId, float $timeoutSeconds): ?float {
    $waiter = $this->relayWaiter ?? throw new \LogicException('start_relay() first');
    $start = microtime(true);
    while (true) {
      // Idle: blocked in LISTEN until a NOTIFY arrives or the poll interval (from going idle) is up.
      $remaining = $timeoutSeconds - (microtime(true) - $start);
      if ($remaining <= 0) {
        return null;
      }
      $poll = max(0.0, self::RELAY_POLL_SECONDS - (microtime(true) - $this->relayIdleSince));
      $waiter->wait(min($poll, $remaining));
      if (microtime(true) - $start > $timeoutSeconds) {
        return null;
      }
      if ($this->relayPassUntilIdle($eventId)) {
        return microtime(true) - $start;
      }
    }
  }

  public function await_wakeup(float $timeoutSeconds): bool {
    return ($this->relayWaiter ?? throw new \LogicException('start_relay() first'))->wait($timeoutSeconds);
  }

  public function drop_next_wakeup(): void {
    $this->relayWakeup?->suppressNext();
  }

  public function poll_seconds(): float {
    return self::RELAY_POLL_SECONDS;
  }

  public function stop_relay(): void {
    if ($this->relayWaiter === null) {
      return;
    }
    $this->relayWaiter = null;
    try {
      $this->ports[self::RELAY_WORKER]->connection->executeStatement('UNLISTEN *');
    } catch (\Throwable) {
    }
  }

  /**
   * Relay passes until one claims nothing (as `ddd:relay` loops without
   * waiting while there is work); then the worker is idle.
   *
   * @return bool whether $eventId was handed to the transport
   */
  private function relayPassUntilIdle(?string $eventId = null): bool {
    $relay = $this->ports[self::RELAY_WORKER]->relay;
    $transported = false;
    do {
      $report = $relay->run_once();
      $transported = $transported || ($eventId !== null && in_array($eventId, $report->accepted, true));
    } while ($report->claimed !== []);
    $this->relayIdleSince = microtime(true);
    return $transported;
  }

  // ── WorkflowHost (CR-W4C4-4) ─────────────────────────────────────────────

  /** `tangible_ddd.workflow_ignitions`, on the app clock as the bundle wires it (CR sf-b-1). */
  public function ignition_ledger(): IWorkflowIgnitionLedger {
    return $this->workflowLedger ??= new DbalWorkflowIgnitionLedger($this->connection, '', $this->clock);
  }

  /** `tangible_ddd.workflow_repository`. */
  public function workflows(): IBehaviourWorkflowRepository {
    return $this->workflowRepository ??= new DbalBehaviourWorkflowRepository($this->events, $this->connection);
  }

  /** `tangible_ddd.work_item_repository` (WorkItemHost, CR-W5C5-3). */
  public function work_items(): IWorkItemRepository {
    return $this->workItems ??= new DbalWorkItemRepository($this->connection);
  }

  // ── CrossConsumerHost (CR-W5C5-4) ────────────────────────────────────────
  //
  // A second consumer of the same app, OTHER, as the bundle wires one: its
  // compiled subscription map is a FactAudience of every worker's
  // MessengerFactTransport, so relay_once() sends it a copy (addressed to
  // it) of each fact its map subscribes to, on its own facts transport;
  // its IntegrationFactHandler runs on its own ledger (tables `sfb_`).

  public function other_subscriptions(): ISubscriptionRegistry {
    $this->otherLedger();
    return $this->otherSubscriptions;
  }

  public function other_ledger(): IDeliveryLedger {
    return $this->otherLedger();
  }

  /**
   * `messenger:consume ddd_facts_sfb` until the due copies are handled, in routing order.
   *
   * Due means Doctrine Messenger's available_at, which is wall clock (gmdate), not the
   * fixture IClock. $eventClass is unused: the interface consumes every copy routed to OTHER.
   */
  public function deliver_routed(string $eventClass): array {
    $due = (int) $this->connection->fetchOne(
      'SELECT count(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= ?',
      [self::OTHER_FACTS, gmdate('Y-m-d H:i:s')],
    );
    if ($due === 0) {
      return [];
    }
    $outcomes = [];
    $events = new EventDispatcher();
    $events->addSubscriber($this->reset);
    $events->addSubscriber(new StopWorkerOnMessageLimitListener($due));
    $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
    $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $e) use (&$outcomes): void {
      $outcomes[] = $e->getEnvelope()->last(HandledStamp::class)?->getResult();
    });
    $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $e) use (&$outcomes): void {
      $failure = $e->getThrowable();
      $outcomes[] = $failure instanceof HandlerFailedException ? self::incomplete($failure)->outcome : throw $failure;
    });
    (new Worker([self::OTHER_FACTS => self::doctrineTransport($this->connection, self::OTHER_FACTS)], $this->otherBus(), $events))->run(['sleep' => 10_000]);
    return $outcomes;
  }

  /** One redelivered copy of a fact the fixture's consumer raised, addressed to OTHER. */
  public function deliver_other(string $eventClass, array $wrapped): DeliveryOutcome {
    $message = new IntegrationFactMessage(
      self::CONSUMER,
      (string) ($wrapped['__event_id'] ?? ''),
      $eventClass::name(),
      $eventClass,
      $eventClass::integration_action(),
      $wrapped,
      self::OTHER,
    );
    try {
      $envelope = $this->otherBus()->dispatch(new Envelope($message));
    } catch (HandlerFailedException $e) {
      return self::incomplete($e)->outcome;
    }
    return $envelope->last(HandledStamp::class)?->getResult()
      ?? throw new \LogicException('IntegrationFactHandler returned no outcome');
  }

  /** OTHER's ledger; its tables are created on first use, so scenarios without the seam pay nothing. */
  private function otherLedger(): DbalDeliveryLedger {
    if ($this->otherLedger === null) {
      if ($this->ownsSchema) {
        PostgresSchema::apply($this->connection, self::OTHER_TABLES);
      }
      $this->otherLedger = new DbalDeliveryLedger($this->connection, self::OTHER_TABLES);
    }
    return $this->otherLedger;
  }

  /** OTHER's delivery bus: its IntegrationFactHandler over its own map and ledger. */
  private function otherBus(): MessageBus {
    $delivery = Factory::delivery($this->otherSubscriptions, $this->otherLedger(), IntegrationDelivery::DEFAULT_BUDGET, $this->logger);
    return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
      IntegrationFactMessage::class => [new IntegrationFactHandler($delivery, self::OTHER)],
    ]))]);
  }

  /** `tangible_ddd.workflow_igniter`: core WorkflowIgniter(ledger, boundary, logger, clock). */
  public function igniter(): WorkflowIgniter {
    return $this->workflowIgniter ??= new WorkflowIgniter($this->ignition_ledger(), $this->boundary(), $this->logger, $this->clock);
  }

  public function effect_bus(array $handlers): CommandBus {
    return $this->bundleBus(
      // The bundle's terminal is SelfExecuting (RecordEffect is a SelfHandlingCommand); the handler map routes it to apply().
      [RecordEffect::class => static fn (RecordEffect $r): EffectResult => $r->apply()] + $handlers,
      new BusOptions(),
      new EffectMiddleware($this->effect_journal(), $this->boundary()),
    );
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function fail_next_commit(string $reason): void {
    $this->middleware->failNextCommit($reason);
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

  public function relay_once(int $limit = 50): RelayReport {
    $r = $this->ports[1]->relay->run_once($limit);
    return new RelayReport($r->claimed, $r->accepted, $r->retried, $r->dead_lettered, $r->lost);
  }

  public function reject_next_submission(?\Throwable $e = null): void {
    $this->ports[1]->factSender->rejectNext($e);
  }

  public function accept_next_without_ref(): void {
    $this->ports[1]->factSender->noIdNext();
  }

  public function crash_next_relay(): void {
    $this->onceAfterSubmit(static function ($claim): void {
      throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
    });
  }

  public function transported(): array {
    $held = $this->consumed;
    foreach ($this->heldRows() as $id => $row) {
      $held[$id] = $row['fact'];
    }
    ksort($held, SORT_NUMERIC);
    return array_values($held);
  }

  public function seed_legacy_fact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
    // No legacy schema on sf: the port record carries the absolute time.
    $eventId = Uuid::v4();
    $this->ports[1]->outbox->append_fact(new OutboxRecord(
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
    return $this->deliverOn($this->ports[1], $eventClass, $wrapped);
  }

  public function deliver_transported(string $eventClass): array {
    return $this->consumeFacts($this->ports[1], PHP_INT_MAX);
  }

  // ── worker ───────────────────────────────────────────────────────────────

  public function run_worker(array $messages): WorkerRun {
    $queue = new MessengerInMemoryTransport();
    foreach (array_values($messages) as $slot => $work) {
      $queue->send(new Envelope(new WorkerTask($slot, \Closure::fromCallable($work))));
    }
    $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
      WorkerTask::class => [static fn (WorkerTask $t) => ($t->work)()],
    ]))]);

    $errors = array_fill(0, count($messages), null);
    $leaks = array_fill(0, count($messages), null);
    $events = new EventDispatcher();
    $events->addSubscriber($this->reset); // the bundle's worker boundary (priority -1024)
    $events->addSubscriber(new StopWorkerOnMessageLimitListener(count($messages)));
    $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
    $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $e) use (&$errors): void {
      $t = $e->getThrowable();
      $errors[$e->getEnvelope()->getMessage()->slot] = $t instanceof HandlerFailedException ? ($t->getPrevious() ?? $t) : $t;
    });
    $collectLeak = function (WorkerMessageHandledEvent|WorkerMessageFailedEvent $e) use (&$leaks): void {
      $leaks[$e->getEnvelope()->getMessage()->slot] = $this->leaks->takeLeak();
    };
    $events->addListener(WorkerMessageHandledEvent::class, $collectLeak, -2048);
    $events->addListener(WorkerMessageFailedEvent::class, $collectLeak, -2048);

    (new Worker(['conformance' => $queue], $bus, $events))->run(['sleep' => 10_000]);

    return new WorkerRun($errors, $leaks);
  }

  public function runner_transients(): ?array {
    $runner = $this->ports[1]->runner;
    // The runner's one per-message transient (register 3.9), read without widening its API.
    return ['resume_argument' => (fn () => $this->resume_argument)->call($runner)];
  }

  // ── AuditSinkFaults, RecordsSignals (CR-CC-1) ────────────────────────────

  public function fail_next_audit_close(string $reason): void {
    $this->auditPort->fail_next_close($reason);
  }

  public function signals(): array {
    return $this->signals;
  }

  // ── RelayRace (CR-W3CP-2), StatementErrors (CR-W3CP-3) ──────────────────

  public function race_next_relay(callable $competitor): void {
    $this->onceAfterSubmit(function () use ($competitor): void {
      // The relay holds its submit + accept transaction open on this
      // connection; the competitor's statements go to a second session.
      $this->connection->asAnotherConnection($this->elsewhere(), $competitor);
    });
  }

  public function fail_statement(): void {
    if (!$this->connection->isTransactionActive()) {
      throw new \LogicException('fail_statement() runs inside the open transaction');
    }
    // A duplicate primary key: Postgres rejects it and aborts the transaction (25P02).
    $this->connection->executeStatement('INSERT INTO ' . ScenarioSchemaMiddleware::FAULT_TABLE . '_parent (id) VALUES (1), (1)');
  }

  // ── ProcessHost (CR-W3CP-1) ──────────────────────────────────────────────

  public function wire_processes(array $starts, array $awaits): void {
    foreach ($starts as $pair) {
      $this->starts[] = $pair;
    }
    foreach ($awaits as $class) {
      $this->awaits[] = $class;
    }
    foreach ($this->ports as $w) {
      self::wire($w->runner, $starts, $awaits);
    }
  }

  public function worker(int $n = 1): ProcessWorker {
    if ($n < 1) {
      throw new \InvalidArgumentException("No worker $n");
    }
    if (!isset($this->workers[$n])) {
      $this->composeWorker($n, $this->openConnection(new ScenarioSchemaMiddleware($this->schema, $this->statementFaults)));
    }
    return $this->workers[$n];
  }

  public function process_store(): IProcessStore {
    return $this->ports[1]->processStore;
  }

  public function wakeups(): IWakeupScheduler {
    return $this->ports[1]->wakeups;
  }

  /**
   * The bundle's `tangible_ddd.operator_view` on worker 1's connection: the
   * core PortOperatorView with the sf ledger and wakeup sources and the
   * core UnrecordedEffects over the effect journal (E2, EffectStateHost).
   */
  public function operator_view(): IOperatorView {
    $w = $this->ports[1];
    return new PortOperatorView($this->consumer, $w->administration, $w->processStore, $this->clock, [
      new DbalLedgerOperatorSource($this->connection, self::CONSUMER),
      new DbalWakeupOperatorSource($this->connection, self::CONSUMER),
      new UnrecordedEffects($this->effect_journal(), self::CONSUMER, $this->clock),
    ]);
  }

  public function consumer_prefix(): string {
    return $this->consumer->prefix();
  }

  public function lock_key(int $processId): LockKey {
    return new LockKey($this->consumer->prefix(), '', $processId);
  }

  public function process_row(int $id): ?ProcessRow {
    $r = $this->connection->fetchAssociative(
      'SELECT id, process_class, status, step_index, version, ignition_key, ignited_by_event_id FROM ddd_processes WHERE id = ?',
      [$id],
      [ParameterType::INTEGER],
    );
    if ($r === false) {
      return null;
    }
    return new ProcessRow(
      (int) $r['id'],
      (string) $r['process_class'],
      (string) $r['status'],
      (int) $r['step_index'],
      (int) $r['version'],
      $r['ignition_key'] === null ? null : (string) $r['ignition_key'],
      $r['ignited_by_event_id'] === null ? null : (string) $r['ignited_by_event_id'],
    );
  }

  public function process_ids(?string $processClass = null): array {
    $ids = $processClass === null
      ? $this->connection->fetchFirstColumn('SELECT id FROM ddd_processes ORDER BY id')
      : $this->connection->fetchFirstColumn('SELECT id FROM ddd_processes WHERE process_class = ? ORDER BY id', [$processClass]);
    return array_map('intval', $ids);
  }

  public function live_intents(): array {
    // Completed intents are deleted on sf; exhausted ones are kept (not completed).
    return array_map(
      [DbalWakeupScheduler::class, 'intent_from_row'],
      $this->connection->fetchAllAssociative('SELECT * FROM ddd_wakeups ORDER BY id'),
    );
  }

  public function hold_lock_elsewhere(int $processId): void {
    $this->elsewhere()->fetchOne('SELECT pg_advisory_lock(?)', [$this->lock_key($processId)->postgres_key()], [ParameterType::INTEGER]);
  }

  public function release_lock_elsewhere(int $processId): void {
    $this->elsewhere()->fetchOne('SELECT pg_advisory_unlock(?)', [$this->lock_key($processId)->postgres_key()], [ParameterType::INTEGER]);
  }

  public function fail_next_lock(string $reason): void {
    $this->statementFaults->failNextAdvisoryLock($reason);
  }

  public function lock_acquisitions(): int {
    return $this->locks->acquisitions;
  }

  public function before_next_lock(callable $fn): void {
    $this->ports[1]->interleaving?->before_next_acquire(static function () use ($fn): void {
      $fn();
    });
  }

  public function fail_next_handoff(string $reason): void {
    $this->wakeFaults->failNext($reason);
  }

  // ── ProcessDecodeFaults (CR-W4C4-3) ──────────────────────────────────────

  public function forget_class(int $processId, string $missingClass): void {
    // sf stores the class in process_class only (business_data is the promoted constructor parameters).
    $this->connection->executeStatement('UPDATE ddd_processes SET process_class = ? WHERE id = ?', [$missingClass, $processId], [ParameterType::STRING, ParameterType::INTEGER]);
  }

  public function stored_status(int $processId): ?string {
    $status = $this->connection->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$processId], [ParameterType::INTEGER]);
    return $status === false ? null : (string) $status;
  }

  public function quarantine_reason(int $processId): ?string {
    $reason = $this->connection->fetchOne('SELECT quarantine_reason FROM ddd_processes WHERE id = ?', [$processId], [ParameterType::INTEGER]);
    return $reason === false || $reason === null ? null : (string) $reason;
  }

  // ── FreshProcesses (CR-W3CP-4) ───────────────────────────────────────────

  public function publish_fresh(DomainEvent&IIntegrationEvent $fact, bool $killAfterCommit): string {
    $run = $this->runFresh('publish', ['fact' => $fact, 'kill' => $killAfterCommit]);
    return (string) ($run['event'] ?? throw new \LogicException('the fresh process published nothing: ' . json_encode($run)));
  }

  public function drain_fresh(): FreshRun {
    return $this->freshRun($this->runFresh('drain', []));
  }

  public function deliver_fresh(string $eventClass, array $wrapped): FreshRun {
    return $this->freshRun($this->runFresh('deliver', ['class' => $eventClass, 'wrapped' => $wrapped]));
  }

  public function start_fresh(LongProcess $process, ?string $dieAfterCommand = null): FreshRun {
    return $this->freshRun($this->runFresh('start', ['process' => $process, 'die' => $dieAfterCommand]));
  }

  // ── WebRequests (CR-W3CP-5) ──────────────────────────────────────────────

  /**
   * A web request: the bundle's runner (StartMode::Deferred) and the
   * fixture's command bus, with every process-lock acquire sent to a
   * PostgresAdvisoryProcessLock over a pooled DSN with PoolerPolicy::Refuse,
   * so any lock attempt inside the request throws PooledConnectionRefused,
   * as on the pooled web connection (register 5.2).
   */
  public function in_web_request(callable $fn): mixed {
    $this->locks->inWebRequest = true;
    try {
      return $fn();
    } finally {
      $this->locks->inWebRequest = false;
    }
  }

  /** The real bundle boot (TestKernel `inband_pooled`: inband_start true on port 6432). */
  public function boot_inband_pooled(): ?\Throwable {
    $kernel = new TestKernel('test', true, 'inband_pooled');
    try {
      $kernel->boot();
      return null;
    } catch (\Throwable $e) {
      return $e;
    } finally {
      $kernel->shutdown();
      // The kernel's boot touches process-wide state; give the scenario its own back.
      $this->provideHostDefaults();
    }
  }

  // ── sf-only read-back ────────────────────────────────────────────────────

  /** @return list<string> subscriber failures of the last drain's delivery stage */
  public function lastDeliveryFailures(): array {
    return $this->lastDeliveryFailures;
  }

  /** Worker 1's DBAL connection (the fixture's one host connection). */
  public function connection(): Connection {
    return $this->connection;
  }

  /** Lock attempts made inside web requests (each was refused). */
  public function webLockAttempts(): int {
    return $this->locks->webAttempts;
  }

  // ── composition ──────────────────────────────────────────────────────────

  private function compose(\DateTimeImmutable $now, bool $createTables): void {
    $this->statementFaults = new StatementFaults();
    $this->middleware = new ScenarioSchemaMiddleware($this->schema, $this->statementFaults);
    $connection = $this->openConnection($this->middleware);
    assert($connection instanceof RaceableConnection);
    $this->connection = $connection;
    $this->ready = true;

    if ($createTables) {
      PostgresSchema::apply($this->connection);
      foreach (ScenarioSchemaMiddleware::faultTableSql() as $sql) {
        $this->connection->executeStatement($sql);
      }
      $this->connection->executeStatement(DbalScenarioRows::createSql());
    }

    $this->logger = new NullLogger();
    $this->consumer = new SymfonyConsumerConfig(self::CONSUMER, 'TangibleDDD\\Conformance', '0.7.0-conformance');
    $this->outboxConfig = new OutboxConfig();
    $this->clock = new FrozenClock($now);
    $this->events = new EventsUnitOfWork();
    $this->rows = new DbalScenarioRows($this->connection);
    $this->dispatcher = new OrderedListenerDispatcher();
    $this->audit = new InMemoryAuditSink();
    $this->auditPort = new FaultInjectingAuditSink($this->audit);
    $this->actors = new ActorContext();
    $this->wakeFaults = new SendFaults();
    $this->locks = new LockCounter();
    $this->signals = [];
    $this->consumed = [];
    $this->starts = [];
    $this->awaits = [];
    $this->ports = [];
    $this->workers = [];
    $this->effectJournal = null;
    $this->relayWakeup = null;
    $this->relayWaiter = null;
    $this->workflowLedger = null;
    $this->workflowRepository = null;
    $this->workflowIgniter = null;
    $this->workItems = null;
    $this->otherSubscriptions = new CompiledSubscriptionRegistry([], new ServiceLocator([]));
    $this->otherLedger = null;
    $this->provideHostDefaults();

    $this->composeWorker(1, $this->connection);
    if ($createTables) {
      $this->ports[1]->facts->setup();
    }

    $this->leaks = new LeakRecordingLogger();
    $this->reset = new DddRuntimeReset($this->events, $this->actors, $this->leaks);
    $this->reset->install();
  }

  /**
   * What the bundle provides at boot (HostDefaultsInstaller, TangibleDddBundle::boot()):
   * the sf signal dispatcher, the app clock and logger, and the behaviour
   * type registry (`tangible_ddd.behaviour_types`, CR-W5CC-4) with the
   * include-time registrations handed over.
   */
  private function provideHostDefaults(): void {
    $types = new BehaviourTypes();
    HostDefaults::provide(IBehaviourTypes::class, $types);
    BaseBehaviourConfig::hand_over_types($types);
    $events = new EventDispatcher();
    $events->addListener(DddSignal::class, function (DddSignal $s): void {
      $this->signals[] = $s->event;
    });
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new SymfonySignalDispatcher(new NullLogger(), $events));
    HostDefaults::provide(IClock::class, $this->clock);
    HostDefaults::provide(LoggerInterface::class, $this->logger);
  }

  private function openConnection(ScenarioSchemaMiddleware $middleware): Connection {
    return DriverManager::getConnection(
      PostgresDatabase::params() + ['wrapperClass' => RaceableConnection::class],
      (new Configuration())->setMiddlewares([$middleware]),
    );
  }

  private function composeWorker(int $n, Connection $c): void {
    $logger = $this->logger;
    $clock = $this->clock;
    $boundary = new DbalTransactionBoundary($c, NestedPolicy::Reject, null, $logger);
    $pauses = new DbalRelayPauseStore($c);
    // D14 as the bundle wires it (relay.listen: true): every append NOTIFYs in its transaction.
    $this->relayWakeup ??= new SuppressibleRelayWakeup(new PostgresNotifyRelayWakeup($this->connection, $logger));
    $notify = $n === 1 ? $this->relayWakeup : new PostgresNotifyRelayWakeup($c, $logger);
    $outbox = new DbalPostgresOutboxStore($c, $pauses, '', $logger, $notify, self::CONSUMER);
    $facts = self::doctrineTransport($c, 'ddd_facts');
    $factSender = new FaultInjectingSender($facts, null, $clock);
    // CrossConsumerHost: OTHER is an audience of this consumer's relay, as the bundle wires every other consumer.
    $audiences = [new FactAudience(self::OTHER, $this->otherSubscriptions, self::doctrineTransport($c, self::OTHER_FACTS))];
    $transport = new MessengerFactTransport($factSender, self::CONSUMER, new OutboxFactClassResolver($outbox), null, $clock, $c, $audiences);
    $processStore = new DbalProcessStore($c, $clock, '', self::STRANDED_AFTER_SECONDS);
    $wakeups = new DbalParkingScheduler($c); // the bundle's wakeup_scheduler (AW2, ICarriesFacts)
    $wakeTransport = self::doctrineTransport($c, 'ddd_wakeups');
    $wakeSender = new FaultInjectingSender($wakeTransport, $this->wakeFaults);

    $backend = new CountingProcessLock(new PostgresAdvisoryProcessLock($c, $logger), $this->locks, $this->webLock());
    $interleaving = $n === 1 ? new InterleavingProcessLock($backend) : null;
    $lock = new ReentrantProcessLock($interleaving ?? $backend, $logger);
    RuntimeReset::guard($lock);

    $subscriptions = new SubscriptionRegistry();
    $runner = Factory::process_runner(
      $this->consumer, $lock, $processStore, $wakeups, $subscriptions, $boundary, $clock,
      $this->startMode === StartMode::InBand, $logger,
    );
    self::wire($runner, $this->starts, $this->awaits);

    $w = new SfWorkerPorts(
      $n, $c, $boundary, $pauses, $outbox,
      new DbalOutboxAdministration($c, $clock),
      $facts, $factSender, $transport,
      new Relay($outbox, $transport, $boundary, $clock, $this->outboxConfig, $logger, $this->consumer),
      new DbalDeliveryLedger($c),
      $subscriptions, $processStore, $wakeups, $wakeTransport, $wakeSender,
      // stranded scan on every pass (a drain's last stage), lease as the bundle default
      new WakeupRelay($wakeups, $processStore, $boundary, $wakeSender, $clock, self::CONSUMER, self::WAKE_LEASE_SECONDS, 0, $logger),
      $interleaving, $lock, $runner,
    );
    $this->ports[$n] = $w;
    $this->workers[$n] = new SfProcessWorker(
      $runner,
      $lock,
      fn (string $eventClass, array $wrapped): DeliveryOutcome => $this->deliverOn($w, $eventClass, $wrapped),
      fn (int $maxItems): DrainReport => $this->drainOn($w, $maxItems),
    );
  }

  private static function doctrineTransport(Connection $c, string $queue): DoctrineTransport {
    return new DoctrineTransport(
      new PostgreSqlConnection(
        PostgreSqlConnection::buildConfiguration("doctrine://default?queue_name=$queue&table_name=messenger_messages&auto_setup=false"),
        $c,
      ),
      new PhpSerializer(),
    );
  }

  /**
   * @param list<array{0: class-string, 1: class-string}> $starts
   * @param list<class-string> $awaits
   */
  private static function wire(ProcessRunner $runner, array $starts, array $awaits): void {
    foreach ($starts as [$process, $event]) {
      $runner->register_start($process, $event);
    }
    foreach ($awaits as $event) {
      $runner->register_event($event);
    }
  }

  /** The web request's lock backend: a pooled DSN (never connected) with the Refuse policy. */
  private function webLock(): PostgresAdvisoryProcessLock {
    return $this->webLock ??= new PostgresAdvisoryProcessLock(
      DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'ddd-web-pooler.invalid', 'port' => 6432, 'dbname' => 'web', 'user' => 'web']),
      $this->logger,
      PoolerPolicy::Refuse,
    );
  }

  /** A second session on the test schema: the "other connection" of the lock and race seams. */
  private function elsewhere(): Connection {
    return $this->elsewhere ??= $this->openConnection(new ScenarioSchemaMiddleware($this->schema, $this->statementFaults));
  }

  private function onceAfterSubmit(\Closure $hook): void {
    $relay = $this->ports[1]->relay;
    $fired = false;
    $relay->between_submit_and_accept(static function ($claim, $ref) use ($relay, $hook, &$fired): void {
      if ($fired) {
        return;
      }
      $fired = true;
      $relay->between_submit_and_accept(null);
      $hook($claim, $ref);
    });
  }

  // ── delivery and drain on a worker ───────────────────────────────────────

  private function deliverOn(SfWorkerPorts $w, string $eventClass, array $wrapped): DeliveryOutcome {
    $message = new IntegrationFactMessage(
      self::CONSUMER,
      (string) ($wrapped['__event_id'] ?? ''),
      $eventClass::name(),
      $eventClass,
      $eventClass::integration_action(),
      $wrapped,
    );
    try {
      $envelope = $this->bus($w)->dispatch(new Envelope($message));
    } catch (HandlerFailedException $e) {
      return self::incomplete($e)->outcome;
    }
    return $envelope->last(HandledStamp::class)?->getResult()
      ?? throw new \LogicException('IntegrationFactHandler returned no outcome');
  }

  /**
   * One pass of the sf workers on $w: `ddd:relay --once` (outbox step,
   * WakeupRelay), then `messenger:consume ddd_facts ddd_wakeups` until
   * the due messages are handled. Reported in the core DrainReport shape.
   */
  private function drainOn(SfWorkerPorts $w, int $maxItems): DrainReport {
    $errors = [];
    $leaks = [];
    $items = 0;
    $relay = null;
    $this->lastDeliveryFailures = [];

    try {
      $report = $w->relay->run_once(max(0, $maxItems));
      $relay = $report->result;
      $items += count($report->claimed);
    } catch (\Throwable $e) {
      $errors[] = 'relay: ' . $e->getMessage();
    }
    $this->betweenStages($leaks);

    $delivered = 0;
    try {
      $outcomes = $this->consumeFacts($w, max(0, $maxItems - $items));
      $delivered = count($outcomes);
      $items += $delivered;
    } catch (\Throwable $e) {
      $errors[] = 'delivery: ' . $e->getMessage();
    }

    $wakes = [ProcessWakeupHandler::COMPLETED => [], ProcessWakeupHandler::RETRIED => [], ProcessWakeupHandler::EXHAUSTED => [], ProcessWakeupHandler::LEASE_LOST => []];
    $stranded = null;
    try {
      $projection = $w->wakeupRelay->run_once(max(0, $maxItems - $items));
      $items += count($projection->projected) + count($projection->failed);
      foreach ($projection->failed as $key) {
        $wakes[ProcessWakeupHandler::RETRIED][] = $key; // the hand-off failed; the intent is retried later
      }
      foreach ($this->consumeWakes($w) as [$key, $outcome]) {
        $wakes[$outcome][] = $key;
      }
      $running = array_values(array_filter(
        $w->processStore->find_stranded($this->clock->now()),
        static fn ($s) => in_array($s->process_id, $projection->reported, true),
      ));
      $stranded = new StrandedScanReport($projection->requeued, $running);
    } catch (\Throwable $e) {
      $errors[] = 'wakeups: ' . $e->getMessage();
    }
    $leak = $this->leaks->takeLeak();
    if ($leak !== null) {
      $leaks[] = $leak->getMessage();
    }

    return new DrainReport(
      $relay,
      $delivered,
      $wakes[ProcessWakeupHandler::COMPLETED],
      [...$wakes[ProcessWakeupHandler::RETRIED], ...$wakes[ProcessWakeupHandler::EXHAUSTED]],
      $wakes[ProcessWakeupHandler::EXHAUSTED],
      $wakes[ProcessWakeupHandler::LEASE_LOST],
      $stranded,
      $items,
      $items >= $maxItems ? DrainReport::STOPPED_MAX_ITEMS : DrainReport::STOPPED_IDLE,
      $leaks,
      $errors,
    );
  }

  /** @param list<string> $leaks */
  private function betweenStages(array &$leaks): void {
    try {
      RuntimeReset::between_messages();
    } catch (RuntimeLeakDetected $e) {
      $leaks[] = $e->getMessage();
    }
  }

  /**
   * Consume the due `ddd_facts` messages on $w's connection with a real
   * Messenger Worker and $w's delivery handler, at most $limit.
   *
   * @return list<DeliveryOutcome>
   */
  private function consumeFacts(SfWorkerPorts $w, int $limit): array {
    $before = $this->heldRows($w->connection, dueOnly: true);
    $count = min($limit, count($before));
    if ($count <= 0) {
      return [];
    }

    $outcomes = [];
    $this->consume($w, 'ddd_facts', $w->facts, $count, function (Envelope $envelope, mixed $result) use (&$outcomes): void {
      $outcomes[] = $result;
      foreach ($result->failed as $sid) {
        $this->lastDeliveryFailures[] = "subscriber $sid failed on {$envelope->getMessage()->event_id}";
      }
    });

    $after = $this->heldRows($w->connection, dueOnly: false);
    foreach ($before as $id => $row) {
      if (!isset($after[$id])) {
        $this->consumed[$id] = $row['fact'];
      }
    }
    return $outcomes;
  }

  /** @return list<array{0: string, 1: string}> [intent key, ProcessWakeupHandler outcome] */
  private function consumeWakes(SfWorkerPorts $w): array {
    $due = (int) $w->connection->fetchOne(
      "SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_wakeups' AND delivered_at IS NULL AND available_at <= ?",
      [gmdate('Y-m-d H:i:s')],
    );
    if ($due === 0) {
      return [];
    }
    $outcomes = [];
    $this->consume($w, 'ddd_wakeups', $w->wakeTransport, $due, static function (Envelope $envelope, mixed $result) use (&$outcomes): void {
      $outcomes[] = [$envelope->getMessage()->key, (string) $result];
    });
    return $outcomes;
  }

  /** @param \Closure(Envelope, mixed): void $onResult the handler's result (an incomplete delivery's outcome on failure) */
  private function consume(SfWorkerPorts $w, string $name, DoctrineTransport $receiver, int $count, \Closure $onResult): void {
    $events = new EventDispatcher();
    $events->addSubscriber($this->reset); // the bundle's worker boundary (priority -1024)
    $events->addSubscriber(new StopWorkerOnMessageLimitListener($count));
    $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
    $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $e) use ($onResult): void {
      $onResult($e->getEnvelope(), $e->getEnvelope()->last(HandledStamp::class)?->getResult());
    });
    $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $e) use ($onResult): void {
      $failure = $e->getThrowable();
      $onResult($e->getEnvelope(), $failure instanceof HandlerFailedException ? self::incomplete($failure)->outcome : throw $failure);
    });

    (new Worker([$name => $receiver], $this->bus($w), $events))->run(['sleep' => 10_000]);
  }

  /** The delivery bus of $w: IntegrationFactHandler and ProcessWakeupHandler, as the bundle tags them. */
  private function bus(SfWorkerPorts $w): MessageBus {
    $delivery = Factory::delivery($w->subscriptions, $w->ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger);
    return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
      IntegrationFactMessage::class => [new IntegrationFactHandler($delivery, self::CONSUMER)],
      // The bundle's process_wake_target: ParkedFacts puts a parked fact back on the intent the message rebuilds (AW2).
      ProcessWakeupMessage::class => [new ProcessWakeupHandler(new ParkedFacts($w->wakeups, new ProcessRunnerWakeTarget($w->runner)), $w->wakeups, $this->clock, $this->logger)],
    ]))]);
  }

  private static function incomplete(HandlerFailedException $e): FactDeliveryIncomplete {
    foreach ($e->getWrappedExceptions() as $wrapped) {
      if ($wrapped instanceof FactDeliveryIncomplete) {
        return $wrapped;
      }
    }
    throw $e;
  }

  /**
   * The `ddd_facts` rows the Doctrine transport holds, with their due time
   * on the HOST clock: `available_at` (wall clock) plus the host-minus-wall
   * offset recorded at the send (CR sfc-2 option (b), W3CP-R1).
   *
   * @return array<string, array{fact: TransportedFact, due: bool}> messenger id => row
   */
  private function heldRows(?Connection $c = null, bool $dueOnly = false): array {
    $c ??= $this->connection;
    $rows = $c->fetchAllAssociative(
      "SELECT id, body, available_at, delivered_at FROM messenger_messages WHERE queue_name = 'ddd_facts' ORDER BY id"
    );
    $wallNow = gmdate('Y-m-d H:i:s');
    $fallback = (float) $this->clock->now()->format('U.u') - microtime(true);
    $serializer = new PhpSerializer();
    $held = [];
    foreach ($rows as $r) {
      $due = $r['delivered_at'] === null && (string) $r['available_at'] <= $wallNow;
      if ($dueOnly && !$due) {
        continue;
      }
      $message = $serializer->decode(['body' => (string) $r['body']])->getMessage();
      if (!$message instanceof IntegrationFactMessage) {
        continue;
      }
      $id = (string) $r['id'];
      $offset = $this->ports[1]->factSender->hostOffsetOf($id) ?? $fallback;
      $wall = (new \DateTimeImmutable((string) $r['available_at'], new \DateTimeZone('UTC')))->getTimestamp();
      $held[$id] = [
        'fact' => new TransportedFact($message->event_id, new \DateTimeImmutable('@' . (int) round($wall + $offset))),
        'due' => $due,
      ];
    }
    return $held;
  }

  // ── fresh processes ──────────────────────────────────────────────────────

  /**
   * Run one fresh `php` process (tests/Conformance/bin/fresh-process.php)
   * against this test's schema, on the host clock's current instant.
   *
   * @return array{event?: string, process?: int, result?: array<string, mixed>, died: bool}
   */
  private function runFresh(string $op, array $args): array {
    $payload = base64_encode(serialize([
      'url' => PostgresDatabase::url(),
      'schema' => $this->schema,
      'now' => $this->clock->now()->format('Y-m-d\TH:i:s.uP'),
      'op' => $op,
      'args' => $args,
    ]));
    $stderr = tempnam(sys_get_temp_dir(), 'ddd-sf-fresh-');
    $proc = proc_open(
      [PHP_BINARY, __DIR__ . '/bin/fresh-process.php'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
      $pipes,
    );
    if (!is_resource($proc)) {
      throw new \RuntimeException('could not start the fresh php process');
    }
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    do {
      $status = proc_get_status($proc);
      if ($status['running']) {
        usleep(5_000);
      }
    } while ($status['running']);
    proc_close($proc);
    $errors = (string) file_get_contents($stderr);
    @unlink($stderr);

    $run = ['died' => $status['signaled'] && $status['termsig'] === 9];
    foreach (explode("\n", $out) as $line) {
      if (str_starts_with($line, '@@ddd ')) {
        $run = (array) json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR) + $run;
      }
    }
    if (!$run['died'] && !isset($run['result'])) {
      throw new \RuntimeException(sprintf(
        "fresh %s process exited %d without a result.\nstdout: %s\nstderr: %s",
        $op, $status['exitcode'], $out, $errors
      ));
    }
    return $run;
  }

  /** @param array{process?: int, result?: array<string, mixed>, died: bool} $run */
  private function freshRun(array $run): FreshRun {
    $result = $run['result'] ?? [];
    return new FreshRun(
      died: $run['died'],
      process_id: isset($result['processId']) ? (int) $result['processId'] : (isset($run['process']) ? (int) $run['process'] : null),
      relayed: array_values($result['relayed'] ?? []),
      delivered: (int) ($result['delivered'] ?? 0),
      errors: array_values($result['errors'] ?? []),
    );
  }

  private function resetStatics(): void {
    RuntimeReset::forget_for_tests();
    HostDefaults::reset_for_tests();
    ConsumerRegistry::reset();
    Correlation::reset();
    Reactions::reset();
  }
}
