<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use League\Tactician\CommandBus;
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
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\Support\ConformanceConfig;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\Support\RecordingLogger;
use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
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
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryRelayPauseStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\RecordingFactObserver;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The mem host: every port is its ddd-core in-memory double
 * (packages/ddd-core/src/Testing), sharing one simulated connection through
 * InMemoryTransactionBoundary (outbox, ledger, the scenario rows and a
 * shared-connection transport are enlisted, so a rollback restores them
 * together).
 *
 * The pipeline is the real ddd-core one (CONF-1..3): CorrelationMiddleware
 * (act bracket + audit through the ports), TransactionalCommandMiddleware,
 * DomainEventsPublishMiddleware over OutboxIntegrationEventBus (port form),
 * the OutboxProcessor relay step (port form), IntegrationDelivery and
 * RuntimeReset. Only the handler map is conformance-owned.
 *
 * HostDefaults gets the logger, the signal dispatcher and the clock (the
 * act bracket reads its clock there), never a transaction boundary:
 * cmd.no-boundary needs a bus without one.
 *
 * "Fresh schema" on mem is a fresh object graph built in setUp().
 */
final class MemHostFixture implements HostFixture {

  public const START = '2026-10-01T00:00:00Z';
  public const CONSUMER_PREFIX = 'conformance';
  public const CONSUMER_VERSION = '0.0.0-conformance';

  private ConformanceConfig $config;
  private FrozenClock $clock;
  private RecordingLogger $logger;
  private RecordingSignalDispatcher $signals;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryRelayPauseStore $pauses;
  private InMemoryOutboxStore $outbox;
  private RecordingOutboxStore $relayStore;
  private InMemoryTransport $transport;
  private InMemoryDeliveryLedger $ledger;
  private SubscriptionRegistry $subscriptions;
  private InMemoryProcessLock $rawLock;
  private ReentrantProcessLock $lock;
  private EventsUnitOfWork $events;
  private InMemoryScenarioRows $rows;
  private OrderedListenerDispatcher $dispatcher;
  private InMemoryAuditSink $audit;
  private RecordingFactObserver $facts;
  private OutboxConfig $outboxConfig;

  /** @var list<string> diagnostics the runtime classes logged, oldest first */
  public array $logs = [];

  private int $deliveredCursor = 0;

  private bool $crashAfterSubmit = false;

  public function __construct(private readonly bool $transportSharesConnection = false) {}

  public function hostName(): string {
    return 'mem';
  }

  public function setUp(ScenarioContext $context): void {
    $this->resetStatics();

    $this->config = new ConformanceConfig(self::CONSUMER_PREFIX, self::CONSUMER_VERSION);
    $this->logger = new RecordingLogger(fn (string $m) => $this->logs[] = $m);
    $this->signals = new RecordingSignalDispatcher();
    $this->outboxConfig = new OutboxConfig();
    $this->clock = new FrozenClock(new \DateTimeImmutable(self::START));
    $this->boundary = new InMemoryTransactionBoundary(NestedPolicy::Reject, $this->logger);
    $this->pauses = new InMemoryRelayPauseStore();
    $this->outbox = new InMemoryOutboxStore($this->clock, $this->pauses, $this->boundary);
    $this->relayStore = new RecordingOutboxStore($this->outbox);
    // CONF-5: a shared-connection transport enlists in the boundary, so a
    // rolled-back relay transaction takes its submission with it.
    $this->transport = new InMemoryTransport($this->transportSharesConnection, $this->boundary);
    $this->ledger = new InMemoryDeliveryLedger();
    $this->subscriptions = new SubscriptionRegistry();
    $this->rawLock = new InMemoryProcessLock();
    $this->lock = new ReentrantProcessLock($this->rawLock, $this->logger);
    $this->events = new EventsUnitOfWork();
    $this->rows = new InMemoryScenarioRows();
    $this->dispatcher = new OrderedListenerDispatcher();
    $this->audit = new InMemoryAuditSink();
    $this->facts = new RecordingFactObserver();

    $this->boundary->enlist($this->outbox);
    $this->boundary->enlist($this->ledger);
    $this->boundary->enlist($this->rows);

    HostDefaults::provide(LoggerInterface::class, $this->logger);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);
    HostDefaults::provide(IClock::class, $this->clock);

    RuntimeReset::register('conformance.events', fn () => $this->events->reset());
    RuntimeReset::guardLock($this->lock);
  }

  public function tearDown(): void {
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
    return $this->outbox;
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
        $this->config,
        $this->events,
        new Redactor(),
        $this->audit,
        new FixedActorProvider(),
        $policy,
        new PhpEnvironmentProvider(['host' => 'mem']),
      ),
      new TransactionalCommandMiddleware($options->withBoundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, new OutboxIntegrationEventBus(
          null,
          $this->config,
          $this->facts,
          $this->clock,
          $this->outbox,
          $this->outboxConfig,
        )),
      ),
      new HandlerMapMiddleware($handlers),
    );
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function failNextCommit(string $reason): void {
    $this->boundary->failNextCommit($reason);
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

  /**
   * One step of the core OutboxProcessor (port form), batch size $limit,
   * over a RecordingOutboxStore so the report names event ids.
   */
  public function relayOnce(int $limit = 50): RelayReport {
    $processor = new OutboxProcessor(
      $this->config,
      null,
      OutboxConfig::from_array(['batch_size' => $limit] + get_object_vars($this->outboxConfig)),
      null,
      null,
      $this->logger,
      $this->clock,
      $this->relayStore,
      $this->transport,
      $this->boundary,
    );

    if ($this->crashAfterSubmit) {
      $this->crashAfterSubmit = false;
      $processor->between_submit_and_accept(static function ($claim): void {
        throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
      });
    }

    $this->relayStore->reset();
    $processor->process_batch();
    return $this->relayStore->report();
  }

  public function rejectNextSubmission(?\Throwable $e = null): void {
    $this->transport->rejectNext($e);
  }

  public function acceptNextSubmissionWithoutRef(): void {
    $this->transport->returnNoRefNext();
  }

  public function crashNextRelayAfterSubmit(): void {
    $this->crashAfterSubmit = true;
  }

  public function transported(): array {
    return array_map(static fn (array $s) => new TransportedFact($s['event_id'], $s['due_at']), $this->held());
  }

  public function seedLegacyDelayedFact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
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
      max_attempts: $this->outboxConfig->max_attempts,
    ));
    return $eventId;
  }

  // ── delivery ─────────────────────────────────────────────────────────────

  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    $delivery = new IntegrationDelivery(
      $this->subscriptions,
      $this->ledger,
      IntegrationDelivery::DEFAULT_BUDGET,
      $this->logger,
    );
    return $delivery->deliver($eventClass, $wrapped);
  }

  public function deliverTransported(string $eventClass): array {
    $held = $this->held();
    $outcomes = [];
    for (; $this->deliveredCursor < count($held); $this->deliveredCursor++) {
      $outcomes[] = $this->deliver($eventClass, $held[$this->deliveredCursor]['envelope']);
    }
    return $outcomes;
  }

  // ── worker ───────────────────────────────────────────────────────────────

  public function runWorker(array $messages): WorkerRun {
    $errors = $leaks = [];
    foreach ($messages as $message) {
      $error = $leak = null;
      try {
        $message();
      } catch (\Throwable $e) {
        $error = $e;
      } finally {
        try {
          RuntimeReset::betweenMessages();
        } catch (RuntimeLeakDetected $l) {
          $leak = $l;
        }
      }
      $errors[] = $error;
      $leaks[] = $leak;
    }
    return new WorkerRun($errors, $leaks);
  }

  public function runnerTransients(): ?array {
    return null; // no process runner on mem until wave 3 (ProcessRunner on the ports)
  }

  // ── mem-only read-back (not HostFixture) ─────────────────────────────────

  public function auditSink(): InMemoryAuditSink {
    return $this->audit;
  }

  public function factObserver(): RecordingFactObserver {
    return $this->facts;
  }

  // ── internals ────────────────────────────────────────────────────────────

  /** @return list<array{event_id: string, envelope: array, due_at: \DateTimeImmutable, ref: ?string}> submissions the transport accepted with a reference */
  private function held(): array {
    return array_values(array_filter($this->transport->submissions, static fn (array $s) => $s['ref'] !== null));
  }

  private function resetStatics(): void {
    RuntimeReset::forgetRegistrationsForTests();
    HostDefaults::resetForTests();
    Correlation::reset();
    Reactions::reset();
  }
}
