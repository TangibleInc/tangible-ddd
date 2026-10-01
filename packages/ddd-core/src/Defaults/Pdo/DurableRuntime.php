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
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Defaults\Pdo\Internal\HandlerMiddleware;
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
 * - the command bus in the frozen core order Correlation → Transaction
 *   (PdoTransactionBoundary) → DomainEventsPublish (OrderedListenerDispatcher
 *   + the outbox integration bus, recording the fact class) →
 *   SelfExecuting → handler; and the query bus (SelfExecuting → handler);
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
 * `$handlers` is either a PSR-11 container or an array: an ICommand /
 * IQuery class key maps that message to its handler (callable, or object
 * with handle()); any other key is a service for handle() injection and
 * convention-named handlers (\Closure = lazy factory receiving the
 * container; object = instance). The runtime's own services (CommandBus,
 * the query bus id, EventsUnitOfWork, ProcessRunner, IProcessEntry,
 * IHostConnection and the connection's class, ITransactionBoundary,
 * IClock, IConsumerIdentity, DurableRuntime) resolve first.
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

    // ── storage: one connection, the pdo adapter set ─────────────────────
    $boundary = new PdoTransactionBoundary($db, logger: $logger);
    $pauses = new PdoPauseStore($db, $tablePrefix, $clock);
    $outbox = new PdoOutboxStore($db, $pauses, $tablePrefix, $clock, $logger);
    $jobs = new PdoJobStore($db, $prefix, $tablePrefix, $clock, $logger);
    $store = new PdoProcessStore($db, $tablePrefix, $clock, logger: $logger);
    $ledger = new PdoDeliveryLedger($db, $tablePrefix, $clock);
    $lock = new ReentrantProcessLock(new MySqlNamedLock($db, $logger), $logger);
    RuntimeReset::guardLock($lock);

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
    $bus = new CommandBus(
      new CorrelationMiddleware($config, $events, new Redactor()),
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
    ] as $id => $service) {
      $container->set($id, $service);
    }
    ConsumerRegistry::add($consumer, static fn () => $container);

    $registrar = new SubscriptionRegistrar($registry, $runner, $container);
    foreach ($listeners as $listener) {
      $registrar->registerListener($listener);
    }
    foreach ($processes as $process) {
      $registrar->registerProcess($process);
    }

    // ── the drain ────────────────────────────────────────────────────────
    $delivery = new IntegrationDelivery($registry, $ledger, IntegrationDelivery::DEFAULT_BUDGET, $logger);
    $drain = new Drain(
      relay: new OutboxProcessor($config, null, $outboxConfig, null, null, $logger, $clock, $outbox, $jobs, $boundary),
      wakeups: $jobs->withClaimKinds(WakeKind::Continue, WakeKind::Timeout, WakeKind::ResumeRetry),
      processWakes: $runner,
      delivery: new PdoDeliveryWorker($jobs, $delivery, self::eventClasses($registry), logger: $logger),
      stranded: $runner,
      clock: $clock,
      logger: $logger,
    );

    $runtime = new self(
      $bus, $queryBus, $drain, new PdoOperatorView($db, $prefix, $tablePrefix, $clock),
      $runner, $jobs, $store, $outbox, $boundary, $localListeners, $container, $consumer,
    );
    $container->set(self::class, $runtime);
    return $runtime;
  }

  /** The command bus (also what `$command->send()` uses for this consumer). */
  public function bus(): CommandBus {
    return $this->bus;
  }

  /** The query bus (SelfExecuting → handler; no act bracket). */
  public function queryBus(): CommandBus {
    return $this->queryBus;
  }

  /**
   * One bounded pass of durable work (register 3.6): relay, deliveries, due
   * wakeups, stranded scan. Call it outside any transaction, from cron, a
   * shutdown function or a worker loop the host writes itself.
   */
  public function drain(int $maxItems = 200, int $maxSeconds = 50): DrainReport {
    return $this->drain->runOnce($maxItems, $maxSeconds);
  }

  /** The core Drain behind drain() (W3C-R5). */
  public function drainer(): Drain {
    return $this->drain;
  }

  public function operatorView(): PdoOperatorView {
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
  public function localListeners(): OrderedListenerDispatcher {
    return $this->localListeners;
  }

  public function container(): ContainerInterface {
    return $this->container;
  }

  public function consumer(): IConsumerIdentity {
    return $this->consumer;
  }

  /** @return array<string, class-string<IIntegrationEvent>> event type → fact class, for jobs without a recorded class */
  private static function eventClasses(RecordingSubscriptionRegistry $registry): array {
    $map = [];
    foreach ($registry->subscribedClasses() as $class) {
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
