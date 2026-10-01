<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Repair\FailStrandedProcess;
use TangibleDDD\Application\Process\Repair\FailStrandedProcessHandler;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcess;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcessHandler;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Defaults\Pdo\Internal\EffectHandlers;
use TangibleDDD\Defaults\Pdo\Internal\HandlerMiddleware;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Defaults\Pdo\Internal\IdentityConfig;
use TangibleDDD\Defaults\Pdo\Internal\RecordingSubscriptionRegistry;
use TangibleDDD\Defaults\Pdo\Internal\RuntimeContainer;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\ConsumerPrefix;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Process\IProcessEntry;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\SystemClock;

/**
 * The raw-PHP composition root of ddd-core (register 3.3, ruling #80,
 * W3C-R5): one call turns the host's database connection, its consumer
 * identity, its handlers, listener classes and process classes into a
 * durable runtime on the pdo adapters. Nothing is discovered from globals
 * or service-id conventions; nothing opens a connection, runs migrations,
 * loops or sleeps.
 *
 *   $runtime = DurableRuntime::compose($db, $consumer, $handlers, $listeners, $processes);
 *   $runtime->bus()->handle(new PlaceOrder(...));      // in the request
 *   $runtime->drain();                                 // from cron / a shutdown function
 *
 * What compose() builds (all on the ONE connection $db, so a command's
 * domain writes, its outbox rows and a deferred process start commit
 * together):
 *
 * - tables `{prefix}_ddd_*` (prefix = $consumer->prefix(); apply
 *   schema/mysql8 with SchemaSql::statements($prefix . '_') first);
 * - the command bus in the frozen core order Correlation → Effect (D1,
 *   wave 4: EffectMiddleware over PdoEffectJournal; other commands pass
 *   through) → Transaction (PdoTransactionBoundary) → DomainEventsPublish
 *   (OrderedListenerDispatcher + the outbox integration bus, recording the
 *   fact class) → SelfExecuting → handler; and the query bus
 *   (SelfExecuting → handler);
 * - the core repair commands ResumeStrandedProcess / FailStrandedProcess
 *   (WP8-10) handled on that bus with the runtime's store, jobs, lock,
 *   clock and boundary, unless $handlers maps them itself;
 * - the D10 stores (O8): PdoBehaviourWorkflowRepository,
 *   PdoWorkItemRepository and PdoWorkflowIgnitionLedger, as services and
 *   accessors, for a host's WorkflowHandler / WorkflowIgniter;
 * - the consumer in ConsumerRegistry (so `$command->send()` and fact names
 *   route here; the namespace root comes from the identity, as for every
 *   consumer);
 * - the SubscriptionRegistrar: each listener (IntegrationTranslator
 *   shape) at Subscriber::LISTENER, each process's #[StartsOn] ignitions
 *   and #[Awaits] resumes;
 * - ProcessRunner on PdoProcessStore, MySqlNamedLock (wrapped in
 *   ReentrantProcessLock and guarded by RuntimeReset) and PdoJobStore.
 *   Start mode: HostDefaults' StartMode if provided, else Deferred, so a
 *   command may start a process atomically with its own writes and the
 *   first step runs in the drain;
 * - a core Drain: relay step (OutboxProcessor, port form, submit + accept
 *   in one transaction), deliveries (PdoDeliveryWorker →
 *   IntegrationDelivery + PdoDeliveryLedger), due wakeups (the runner as
 *   wake handler), stranded scan (the runner);
 * - PdoOperatorView over the same tables.
 *
 * Wave 5:
 *
 * - the jobs table is a PdoParkingJobStore (ICarriesFacts, AW2): a fact
 *   resume that cannot take its process lock is parked as a ResumeRetry job
 *   carrying the fact (schema 011) and the delivery completes; the drain
 *   resumes the process once the lock is free;
 * - EffectMiddleware finds the IExternalEffectHandler of a handler-class
 *   effect (E1) like the handler middleware finds a handler: the
 *   array-form `$handlers` entry keyed by the effect command's class, else
 *   the convention-named handler in the container;
 * - the journal keeps entry states (E2, schema 010) and the operator view
 *   lists unrecorded effects (layer `effect`, repair invalidate);
 * - one behaviour type registry (W2): HostDefaults' IBehaviourTypes, or a
 *   BehaviourTypes compose() provides there; register_type() calls made
 *   before compose() are handed over to it (behaviour_types(), and the
 *   container's IBehaviourTypes).
 *
 * `$handlers` is either a PSR-11 container or an array: an ICommand /
 * IQuery class key maps that message to its handler (callable, or object
 * with handle()); any other key is a service for handle() injection and
 * convention-named handlers (\Closure = lazy factory receiving the
 * container; object = instance). The runtime's own services (CommandBus,
 * the query bus id, EventsUnitOfWork, ProcessRunner, IProcessEntry,
 * IHostConnection and the connection's class, ITransactionBoundary,
 * IClock, IConsumerIdentity, DurableRuntime, IEffectJournal,
 * IBehaviourWorkflowRepository, IWorkItemRepository,
 * IWorkflowIgnitionLedger and their pdo classes) resolve first.
 *
 * Logging: HostDefaults' LoggerInterface when provided, else each
 * component's default (core: the host logger or error_log; never silent).
 *
 * Errors: \InvalidArgumentException for a prefix outside [a-z0-9_]+, a
 * listener that is not one, a process that is not a LongProcess;
 * PdoConfigurationError when the PDO is not in ERRMODE_EXCEPTION.
 */
final class DurableRuntime {

  /** The container id QueryBusAware::send() resolves (register 3.1, A F-29). */
  public const QUERY_BUS_ID = 'tactician.query_bus';

  private function __construct(
    private readonly CommandBus $bus,
    private readonly CommandBus $queryBus,
    private readonly Drain $drain,
    private readonly PdoOperatorView $operatorView,
    private readonly ProcessRunner $runner,
    private readonly PdoJobStore $jobs,
    private readonly PdoProcessStore $processes,
    private readonly PdoOutboxStore $outbox,
    private readonly ITransactionBoundary $boundary,
    private readonly OrderedListenerDispatcher $localListeners,
    private readonly ContainerInterface $container,
    private readonly IConsumerIdentity $consumer,
    private readonly PdoEffectJournal $effectJournal,
    private readonly PdoBehaviourWorkflowRepository $workflows,
    private readonly PdoWorkItemRepository $workItems,
    private readonly PdoWorkflowIgnitionLedger $workflowIgnitions,
    private readonly IBehaviourTypes $behaviourTypes,
  ) {}

  /**
   * @param ContainerInterface|array<class-string, callable|object> $handlers
   * @param list<class-string> $listeners
   * @param list<class-string<LongProcess>> $processes
   */
  public static function compose(
    IHostConnection $db,
    IConsumerIdentity $consumer,
    ContainerInterface|array $handlers,
    array $listeners,
    array $processes,
    ?IClock $clock = null,
  ): DurableRuntime {
    $prefix = (new ConsumerPrefix($consumer->prefix()))->prefix();
    $tablePrefix = $prefix . '_';
    $clock ??= HostDefaults::get(IClock::class) ?? new SystemClock();
    $logger = HostDefaults::get(LoggerInterface::class);
    $logger = $logger instanceof LoggerInterface ? $logger : null;
    $config = $consumer instanceof IDDDConfig ? $consumer : new IdentityConfig($consumer);
    $outboxConfig = new OutboxConfig();

    $container = new RuntimeContainer($handlers);

    // W2: one behaviour type registry per process, shared by every runtime;
    // the include-time register_type() calls are handed over to it.
    $types = HostDefaults::get(IBehaviourTypes::class);
    if (!$types instanceof IBehaviourTypes) {
      $types = new BehaviourTypes();
      HostDefaults::provide(IBehaviourTypes::class, $types);
    }
    BaseBehaviourConfig::hand_over_types($types);

    // ── storage: one connection, the pdo adapter set ─────────────────────
    $boundary = new PdoTransactionBoundary($db, logger: $logger);
    $pauses = new PdoPauseStore($db, $tablePrefix, $clock);
    $outbox = new PdoOutboxStore($db, $pauses, $tablePrefix, $clock, $logger);
    // AW2: a contended fact resume is parked as a fact-carrying ResumeRetry job.
    $jobs = new PdoParkingJobStore($db, $prefix, $tablePrefix, $clock, $logger);
    $store = new PdoProcessStore($db, $tablePrefix, $clock, logger: $logger);
    $ledger = new PdoDeliveryLedger($db, $tablePrefix, $clock);
    $lock = new ReentrantProcessLock(new MySqlNamedLock($db, $logger), $logger);
    RuntimeReset::guard($lock);
    $effectJournal = new PdoEffectJournal($db, $tablePrefix, $clock);

    // ── command and query buses (register 3.2 frozen order) ──────────────
    $events = new EventsUnitOfWork();
    RuntimeReset::register("ddd.pdo.$prefix.events", static fn () => $events->reset());
    $localListeners = new OrderedListenerDispatcher();
    $integrationBus = new FactClassRecordingEventBus(
      new OutboxIntegrationEventBus(null, $config, null, $clock, $outbox, $outboxConfig),
      $outbox,
    );
    $handlerMiddleware = new HandlerMiddleware($container);
    $selfExecuting = new SelfExecutingCommandMiddleware($container);
    $effectHandlers = new EffectHandlers($container);
    $bus = new CommandBus(
      new CorrelationMiddleware($config, $events, new Redactor()),
      new EffectMiddleware($effectJournal, $boundary, $effectHandlers, $effectHandlers),
      new TransactionalCommandMiddleware($boundary),
      new DomainEventsPublishMiddleware($events, new EventRouter($localListeners, $integrationBus)),
      $selfExecuting,
      $handlerMiddleware,
    );
    $queryBus = new CommandBus($selfExecuting, $handlerMiddleware);

    // ── processes and subscriptions ──────────────────────────────────────
    $registry = new RecordingSubscriptionRegistry(new SubscriptionRegistry());
    $startMode = HostDefaults::get(StartMode::class);
    $runner = new ProcessRunner(
      $config, null, $lock, $store, $jobs, $registry, $boundary, $clock,
      $startMode instanceof StartMode ? $startMode : StartMode::Deferred,
      $logger,
    );

    // ── D10 stores, on the same connection ───────────────────────────────
    $workflows = new PdoBehaviourWorkflowRepository($events, $db, $tablePrefix, $clock);
    $workItems = new PdoWorkItemRepository($db, $tablePrefix, $clock);
    $workflowIgnitions = new PdoWorkflowIgnitionLedger($db, $tablePrefix, $clock);

    // ── the core operator repairs (WP8-10), dispatchable on the bus ──────
    $container->set_default_handler(ResumeStrandedProcess::class, new ResumeStrandedProcessHandler($store, $jobs, $lock, $clock, $boundary));
    $container->set_default_handler(FailStrandedProcess::class, new FailStrandedProcessHandler($store, $jobs, $lock, $clock, $boundary));

    foreach ([
      CommandBus::class => $bus,
      self::QUERY_BUS_ID => $queryBus,
      EventsUnitOfWork::class => $events,
      ProcessRunner::class => $runner,
      IProcessEntry::class => $runner,
      IHostConnection::class => $db,
      get_class($db) => $db,
      ITransactionBoundary::class => $boundary,
      PdoTransactionBoundary::class => $boundary,
      IClock::class => $clock,
      IConsumerIdentity::class => $consumer,
      OrderedListenerDispatcher::class => $localListeners,
      IEffectJournal::class => $effectJournal,
      PdoEffectJournal::class => $effectJournal,
      IBehaviourWorkflowRepository::class => $workflows,
      PdoBehaviourWorkflowRepository::class => $workflows,
      IWorkItemRepository::class => $workItems,
      PdoWorkItemRepository::class => $workItems,
      IWorkflowIgnitionLedger::class => $workflowIgnitions,
      PdoWorkflowIgnitionLedger::class => $workflowIgnitions,
      IBehaviourTypes::class => $types,
    ] as $id => $service) {
      $container->set($id, $service);
    }
    ConsumerRegistry::add($consumer, static fn () => $container);

    $registrar = new SubscriptionRegistrar($registry, $runner, $container);
    foreach ($listeners as $listener) {
      $registrar->register_listener($listener);
    }
    foreach ($processes as $process) {
      $registrar->register_process($process);
    }

    // ── the drain ────────────────────────────────────────────────────────
    $delivery = new IntegrationDelivery($registry, $ledger, IntegrationDelivery::DEFAULT_BUDGET, $logger);
    $drain = new Drain(
      relay: new OutboxProcessor($config, null, $outboxConfig, null, null, $logger, $clock, $outbox, $jobs, $boundary),
      wakeups: $jobs->claiming(WakeKind::Continue, WakeKind::Timeout, WakeKind::ResumeRetry),
      processWakes: $runner,
      delivery: new PdoDeliveryWorker($jobs, $delivery, self::eventClasses($registry), logger: $logger),
      stranded: $runner,
      clock: $clock,
      logger: $logger,
    );

    $runtime = new self(
      $bus, $queryBus, $drain, new PdoOperatorView($db, $prefix, $tablePrefix, $clock),
      $runner, $jobs, $store, $outbox, $boundary, $localListeners, $container, $consumer,
      $effectJournal, $workflows, $workItems, $workflowIgnitions, $types,
    );
    $container->set(self::class, $runtime);
    return $runtime;
  }

  /** The command bus (also what `$command->send()` uses for this consumer). */
  public function bus(): CommandBus {
    return $this->bus;
  }

  /** The query bus (SelfExecuting → handler; no act bracket). */
  public function query_bus(): CommandBus {
    return $this->queryBus;
  }

  /**
   * One bounded pass of durable work (register 3.6): relay, deliveries, due
   * wakeups, stranded scan. Call it outside any transaction, from cron, a
   * shutdown function or a worker loop the host writes itself.
   */
  public function drain(int $maxItems = 200, int $maxSeconds = 50): DrainReport {
    return $this->drain->run_once($maxItems, $maxSeconds);
  }

  /** The core Drain behind drain() (W3C-R5). */
  public function drainer(): Drain {
    return $this->drain;
  }

  public function operator_view(): PdoOperatorView {
    return $this->operatorView;
  }

  public function runner(): ProcessRunner {
    return $this->runner;
  }

  /** The jobs table: wakeup scheduler and relay transport (repairs, diagnostics). */
  public function jobs(): PdoJobStore {
    return $this->jobs;
  }

  public function processes(): PdoProcessStore {
    return $this->processes;
  }

  public function outbox(): PdoOutboxStore {
    return $this->outbox;
  }

  public function boundary(): ITransactionBoundary {
    return $this->boundary;
  }

  /** Local (in-transaction) domain-event listeners: ->listen(DomainEventClass, callable). */
  public function listeners(): OrderedListenerDispatcher {
    return $this->localListeners;
  }

  public function container(): ContainerInterface {
    return $this->container;
  }

  public function consumer(): IConsumerIdentity {
    return $this->consumer;
  }

  /** The D1 journal EffectMiddleware uses (wave 4); repairs call invalidate() in their own transaction. */
  public function journal(): PdoEffectJournal {
    return $this->effectJournal;
  }

  /** D10 (O8, wave 4): the behaviour workflow store on the runtime's connection and events unit of work. */
  public function workflows(): PdoBehaviourWorkflowRepository {
    return $this->workflows;
  }

  public function work_items(): PdoWorkItemRepository {
    return $this->workItems;
  }

  /** The workflow ignition ledger, for a core WorkflowIgniter (with boundary() and the runtime's clock). */
  public function ignitions(): PdoWorkflowIgnitionLedger {
    return $this->workflowIgnitions;
  }

  /** W2 (wave 5): the behaviour type registry stored workflows decode through (HostDefaults' IBehaviourTypes). */
  public function behaviour_types(): IBehaviourTypes {
    return $this->behaviourTypes;
  }

  /** @return array<string, class-string<IIntegrationEvent>> event type → fact class, for jobs without a recorded class */
  private static function eventClasses(RecordingSubscriptionRegistry $registry): array {
    $map = [];
    foreach ($registry->fact_classes() as $class) {
      if (!class_exists($class) || !is_a($class, IIntegrationEvent::class, true)) {
        continue; // a marker interface names no single class
      }
      try {
        $map[$class::name()] ??= $class;
      } catch (\Throwable) {
        // a fact no registered consumer owns: its row carries the class or it cannot be delivered here
      }
    }
    return $map;
  }
}
