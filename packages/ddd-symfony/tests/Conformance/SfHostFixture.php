<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use League\Tactician\CommandBus;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport as MessengerInMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\Audit\AuditEverything;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Symfony\Messenger\FactDeliveryIncomplete;
use TangibleDDD\Symfony\Messenger\IntegrationFactHandler;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\PostgresSchema;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\Actor\SecurityUserActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SymfonyActorProvider;
use TangibleDDD\Symfony\Runtime\DddRuntimeReset;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Tests\Conformance\Support\DbalScenarioRows;
use TangibleDDD\Symfony\Tests\Conformance\Support\FaultInjectingSender;
use TangibleDDD\Symfony\Tests\Conformance\Support\LeakRecordingLogger;
use TangibleDDD\Symfony\Tests\Conformance\Support\ScenarioSchemaMiddleware;
use TangibleDDD\Symfony\Tests\Conformance\Support\WorkerTask;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The sf host of the shared conformance scenarios (register section 4):
 * Postgres 16 through DBAL 4, Messenger's Doctrine transport, and the
 * ddd-symfony classes the bundle wires (config/services.php), composed by
 * hand so each scenario can pick its handlers, listeners and bus options.
 *
 * - Fresh schema per test: setUp() creates a Postgres schema named
 *   ScenarioContext::uniqueName('sf'), applies schema/postgres, the Doctrine
 *   transport table and the scenario table, and pins every physical
 *   connection to it (search_path, ScenarioSchemaMiddleware). tearDown()
 *   drops it. Nothing is wrapped in a per-test transaction.
 * - ONE DBAL connection: boundary, outbox, administration, pauses, ledger,
 *   scenario rows and the Messenger transport all write through it, so the
 *   relay hand-off is the shared-connection one (submit + accept in one
 *   transaction).
 * - Command bus in the bundle's frozen order: the act bracket
 *   (`tangible_ddd.middleware.act_bracket`), TransactionalCommandMiddleware
 *   over DbalTransactionBoundary (Reject), DomainEventsPublishMiddleware over
 *   OrderedListenerDispatcher and the bundle's integration bus, then the
 *   scenario's handler map. Audit goes to an in-memory sink: sf has no audit
 *   table in wave 2.
 * - Relay: TangibleDDD\Symfony\Runtime\Relay, the core relay step that
 *   `ddd:relay` runs, with the core seam between submit and accept.
 * - Delivery: IntegrationFactHandler on a Messenger bus. deliverTransported()
 *   consumes the Doctrine transport with a real Messenger Worker.
 * - Worker: a real Messenger Worker with DddRuntimeReset subscribed.
 * - COMMIT failure: a deferred foreign key violated at COMMIT, so Postgres
 *   itself rejects the COMMIT.
 * - The process lock is the core ReentrantProcessLock over the Postgres
 *   session advisory lock on the scenario connection (register 5.2).
 * - The act bracket and integration bus are the core CorrelationMiddleware
 *   and OutboxIntegrationEventBus (behind the sf class-recording decorator),
 *   as the bundle wires them since wave 3.
 */
final class SfHostFixture implements HostFixture {

  public const CONSUMER = 'sfc';

  private string $schema;
  private ScenarioSchemaMiddleware $middleware;
  private Connection $connection;
  private FrozenClock $clock;
  private DbalTransactionBoundary $boundary;
  private DbalRelayPauseStore $pauses;
  private DbalPostgresOutboxStore $outbox;
  private DbalOutboxAdministration $administration;
  private DbalDeliveryLedger $ledger;
  private SubscriptionRegistry $subscriptions;
  private ReentrantProcessLock $lock;
  private EventsUnitOfWork $events;
  private DbalScenarioRows $rows;
  private OrderedListenerDispatcher $dispatcher;
  private InMemoryAuditSink $audit;
  private OutboxConfig $outboxConfig;
  private SymfonyConsumerConfig $consumer;
  private DoctrineTransport $doctrine;
  private FaultInjectingSender $sender;
  private MessengerFactTransport $transport;
  private Relay $relay;
  private ActorContext $actors;
  private DddRuntimeReset $reset;
  private LeakRecordingLogger $leaks;

  /** @var array<string, TransportedFact> messenger id => fact, for messages already consumed (ack deletes the row) */
  private array $consumed = [];

  private bool $ready = false;

  public function hostName(): string {
    return 'sf';
  }

  public function setUp(ScenarioContext $context): void {
    $this->resetStatics();
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new RecordingSignalDispatcher());

    $this->schema = $context->uniqueName('sf');
    $admin = PostgresDatabase::connect();
    try {
      $admin->executeStatement('CREATE SCHEMA ' . $this->schema);
    } finally {
      $admin->close();
    }

    $this->middleware = new ScenarioSchemaMiddleware($this->schema);
    $this->connection = DriverManager::getConnection(
      PostgresDatabase::params(),
      (new Configuration())->setMiddlewares([$this->middleware]),
    );
    $this->ready = true;

    PostgresSchema::apply($this->connection);
    foreach (ScenarioSchemaMiddleware::faultTableSql() as $sql) {
      $this->connection->executeStatement($sql);
    }
    $this->connection->executeStatement(DbalScenarioRows::createSql());

    $logger = new NullLogger();
    $this->consumer = new SymfonyConsumerConfig(self::CONSUMER, 'TangibleDDD\\Conformance', '0.7.0-conformance');
    $this->outboxConfig = new OutboxConfig();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    $this->boundary = new DbalTransactionBoundary($this->connection, NestedPolicy::Reject, null, $logger);
    $this->pauses = new DbalRelayPauseStore($this->connection);
    $this->outbox = new DbalPostgresOutboxStore($this->connection, $this->pauses, '', $logger);
    $this->administration = new DbalOutboxAdministration($this->connection, $this->clock);
    $this->ledger = new DbalDeliveryLedger($this->connection);
    $this->subscriptions = new SubscriptionRegistry();
    $this->lock = new ReentrantProcessLock(new PostgresAdvisoryProcessLock($this->connection, $logger), $logger);
    $this->events = new EventsUnitOfWork();
    $this->rows = new DbalScenarioRows($this->connection);
    $this->dispatcher = new OrderedListenerDispatcher();
    $this->audit = new InMemoryAuditSink();
    $this->actors = new ActorContext();

    $this->doctrine = new DoctrineTransport(
      new PostgreSqlConnection(
        PostgreSqlConnection::buildConfiguration('doctrine://default?queue_name=ddd_facts&table_name=messenger_messages&auto_setup=false'),
        $this->connection,
      ),
      new PhpSerializer(),
    );
    $this->doctrine->setup();
    $this->sender = new FaultInjectingSender($this->doctrine);
    $this->transport = new MessengerFactTransport(
      $this->sender,
      self::CONSUMER,
      new OutboxFactClassResolver($this->outbox),
      null,
      $this->clock,
      $this->connection,
    );
    $this->relay = new Relay($this->outbox, $this->transport, $this->boundary, $this->clock, $this->outboxConfig, $logger, $this->consumer);

    $this->leaks = new LeakRecordingLogger();
    $this->reset = new DddRuntimeReset($this->events, $this->actors, $this->leaks);
    $this->reset->install();
    RuntimeReset::guardLock($this->lock);
  }

  public function tearDown(): void {
    if ($this->ready) {
      $this->ready = false;
      try {
        if ($this->connection->isTransactionActive()) {
          $this->connection->rollBack();
        }
      } catch (\Throwable) {
      }
      $this->connection->close();
      try {
        $admin = PostgresDatabase::connect();
        $admin->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        $admin->close();
      } catch (\Throwable) {
      }
    }
    $this->resetStatics();
  }

  // ── time ─────────────────────────────────────────────────────────────────

  public function clock(): IClock {
    return $this->clock;
  }

  public function advanceClock(int $seconds): void {
    $this->clock->advance("+{$seconds} seconds");
  }

  // ── ports ────────────────────────────────────────────────────────────────

  public function boundary(): ITransactionBoundary {
    return $this->boundary;
  }

  public function outbox(): IOutboxStore {
    return $this->outbox;
  }

  public function outboxAdministration(): IOutboxAdministration {
    return $this->administration;
  }

  public function relayPauses(): IRelayPauseStore {
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

  public function processLock(): IProcessLock {
    return $this->lock;
  }

  public function events(): EventsUnitOfWork {
    return $this->events;
  }

  public function scenarioRows(): ScenarioRows {
    return $this->rows;
  }

  // ── command pipeline ─────────────────────────────────────────────────────

  public function commandBus(array $handlers, BusOptions $options = new BusOptions()): CommandBus {
    $policy = $options->audit ? new AuditEverything() : new class implements IAuditPolicy {
      public function audits(object $command): bool {
        return false;
      }

      public function captureParameters(object $command): bool {
        return false;
      }
    };

    return new CommandBus(
      new CorrelationMiddleware(
        $this->consumer,
        $this->events,
        new Redactor(),
        $this->audit,
        new SymfonyActorProvider($this->actors, new SecurityUserActorProvider(null)),
        $policy,
        new PhpEnvironmentProvider(['host' => 'sf']),
      ),
      new TransactionalCommandMiddleware($options->withBoundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, Factory::integrationBus($this->outbox, $this->clock, $this->consumer, $this->outboxConfig)),
      ),
      new HandlerMapMiddleware($handlers),
    );
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function failNextCommit(string $reason): void {
    $this->middleware->failNextCommit($reason);
  }

  public function auditTrail(): array {
    $names = [];
    foreach ($this->audit->opened as $open) {
      $names[$open->commandId] = $open->commandName;
    }
    return array_map(
      static fn ($close) => new AuditEntry($close->commandId, $names[$close->commandId] ?? '?', $close->status, $close->error['type'] ?? null),
      $this->audit->closed,
    );
  }

  // ── relay ────────────────────────────────────────────────────────────────

  public function relayOnce(int $limit = 50): RelayReport {
    $r = $this->relay->runOnce($limit);
    return new RelayReport($r->claimed, $r->accepted, $r->retried, $r->deadLettered, $r->lost);
  }

  public function rejectNextSubmission(?\Throwable $e = null): void {
    $this->sender->rejectNext($e);
  }

  public function acceptNextSubmissionWithoutRef(): void {
    $this->sender->noIdNext();
  }

  public function crashNextRelayAfterSubmit(): void {
    $relay = $this->relay;
    $relay->betweenSubmitAndAccept(static function ($claim) use ($relay): void {
      $relay->betweenSubmitAndAccept(null);
      throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
    });
  }

  public function transported(): array {
    $held = $this->consumed;
    foreach ($this->heldRows() as $id => $fact) {
      $held[$id] = $fact;
    }
    ksort($held, SORT_NUMERIC);
    return array_values($held);
  }

  public function seedLegacyDelayedFact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
    // No legacy schema on sf: the port record carries the absolute time.
    $eventId = Uuid::v4();
    $this->outbox->appendFact(new OutboxRecord(
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
    $message = new IntegrationFactMessage(
      self::CONSUMER,
      (string) ($wrapped['__event_id'] ?? ''),
      $eventClass::name(),
      $eventClass,
      $eventClass::integration_action(),
      $wrapped,
    );
    try {
      $envelope = $this->deliveryBus()->dispatch(new Envelope($message));
    } catch (HandlerFailedException $e) {
      return self::incomplete($e)->outcome;
    }
    return $envelope->last(HandledStamp::class)?->getResult()
      ?? throw new \LogicException('IntegrationFactHandler returned no outcome');
  }

  public function deliverTransported(string $eventClass): array {
    $pending = $this->heldRows();
    if ($pending === []) {
      return [];
    }

    $outcomes = [];
    $events = new EventDispatcher();
    $events->addSubscriber($this->reset);
    $events->addSubscriber(new StopWorkerOnMessageLimitListener(count($pending)));
    $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
    $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $e) use (&$outcomes): void {
      $outcomes[] = $e->getEnvelope()->last(HandledStamp::class)?->getResult();
    });
    $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $e) use (&$outcomes): void {
      $failure = $e->getThrowable();
      $outcomes[] = $failure instanceof HandlerFailedException ? self::incomplete($failure)->outcome : throw $failure;
    });

    (new Worker(['ddd_facts' => $this->doctrine], $this->deliveryBus(), $events))->run(['sleep' => 10_000]);

    foreach ($pending as $id => $fact) {
      $this->consumed[$id] = $fact;
    }
    return $outcomes;
  }

  // ── worker ───────────────────────────────────────────────────────────────

  public function runWorker(array $messages): WorkerRun {
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

  public function runnerTransients(): ?array {
    return null; // no process runner on sf until wave 3
  }

  // ── internals ────────────────────────────────────────────────────────────

  private function deliveryBus(): MessageBus {
    $delivery = Factory::delivery($this->subscriptions, $this->ledger, IntegrationDelivery::DEFAULT_BUDGET, new NullLogger());
    return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
      IntegrationFactMessage::class => [new IntegrationFactHandler($delivery, self::CONSUMER)],
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

  /** @return array<string, TransportedFact> messenger id => fact, for the rows the Doctrine transport holds */
  private function heldRows(): array {
    $availableAt = $this->connection->fetchAllKeyValue(
      "SELECT id, available_at FROM messenger_messages WHERE queue_name = 'ddd_facts' ORDER BY id"
    );
    $held = [];
    foreach ($this->doctrine->all() as $envelope) {
      $id = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
      $message = $envelope->getMessage();
      if ($message instanceof IntegrationFactMessage && isset($availableAt[$id])) {
        $held[$id] = new TransportedFact($message->eventId, new \DateTimeImmutable($availableAt[$id], new \DateTimeZone('UTC')));
      }
    }
    ksort($held, SORT_NUMERIC);
    return $held;
  }

  private function resetStatics(): void {
    RuntimeReset::forgetRegistrationsForTests();
    HostDefaults::resetForTests();
    ConsumerRegistry::reset();
    Correlation::reset();
    Reactions::reset();
  }
}
