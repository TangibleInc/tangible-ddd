<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use League\Tactician\CommandBus;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
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
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Tests\Integration\Conformance\Support\ActionSchedulerTransport;
use TangibleDDD\Tests\Integration\Conformance\Support\BufferLogger;
use TangibleDDD\Tests\Integration\Conformance\Support\LedgerGatedSubscriptions;
use TangibleDDD\Tests\Integration\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Tests\Integration\Conformance\Support\ScenarioTime;
use TangibleDDD\Tests\Integration\Conformance\Support\WpdbFaults;
use TangibleDDD\Tests\Integration\Conformance\Support\WpdbScenarioRows;
use TangibleDDD\Tests\Integration\Conformance\Support\WpHookDomainDispatcher;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\HasActionSubscriberProbe;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpActorProvider;
use TangibleDDD\WordPress\Adapter\WpdbAuditSink;
use TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;
use TangibleDDD\WordPress\Adapter\WpEnvironmentProvider;
use TangibleDDD\WordPress\Adapter\WpHookSignalDispatcher;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;
use TangibleDDD\WordPress\Adapter\WpHostPortFactory;
use TangibleDDD\WordPress\Adapter\WpOptionsOutboxConfigReader;

use function TangibleDDD\WordPress\install_command_audit_table;
use function TangibleDDD\WordPress\install_outbox_tables;
use function TangibleDDD\WordPress\install_touches_table;

/**
 * The wp host of the shared conformance scenarios (register section 4,
 * column wp; section 8 wave 2): WordPress 7.1.2 on MySQL 8.0, inside the WP
 * integration bootstrap, every port bound to the one global `$wpdb`.
 *
 * Ports are the TRANSITIONAL wave-2 adapters of packages/ddd-wp/wordpress/Adapter:
 *
 *   boundary              WpdbTransactionBoundary (checked, NestedPolicy::Reject)
 *   outbox                WpdbOutboxStore over the 0.6 OutboxRepository and schema
 *   outboxAdministration  WpdbOutboxAdministration (replay keeps the event id)
 *   subscriptions         WpHookSubscriptionRegistry (add_action per subscriber),
 *                         each handle behind a ledger gate (Support\LedgerGatedSubscriptions)
 *   processLock           ReentrantProcessLock(GetLockProcessLock), guarded by RuntimeReset
 *   audit                 WpdbAuditSink on the consumer's 0.6 command_audit table;
 *                         "audit off" is 0.6's NullAuditSink
 *   actor / environment   WpActorProvider, WpEnvironmentProvider
 *   signals               WpHookSignalDispatcher (the two 0.6 actions)
 *
 * Pipeline: the core CorrelationMiddleware (act bracket) → core
 * TransactionalCommandMiddleware(WpdbTransactionBoundary) →
 * DomainEventsPublishMiddleware(EventRouter(the 0.6 WordPressEventDispatcher,
 * the port-form OutboxIntegrationEventBus over WpdbOutboxStore)) → handler.
 * Relay: the core OutboxProcessor in its port form (WpdbOutboxStore +
 * Action Scheduler transport + WpdbTransactionBoundary + the host clock).
 * Delivery: the Action Scheduler action itself (ActionScheduler runner
 * process_action), i.e. do_action on the fact's legacy integration hook.
 *
 * Isolation: a fresh consumer per test, prefix = ScenarioContext::uniqueName('wp'),
 * so its tables (`{wp_prefix}{prefix}_integration_outbox`, `_integration_dlq`,
 * `_command_audit`, `_touches`, `_scenario_rows`), options and Action
 * Scheduler group (`{prefix}-outbox`) are its own. Nothing is wrapped in a
 * per-test transaction. tearDown() drops the tables, deletes the group's
 * actions, unbinds every hook it added and restores ddd-wp's HostDefaults.
 *
 * Fixture stand-ins (each a change request in
 * docs/extraction/wave2-wp-conformance-change-requests.md):
 *   - the Action Scheduler ITransport (WPC-1; ddd-wp has none in wave 2),
 *   - the ledger gate over an in-memory ledger (WPC-2; schema v8 is wave 3),
 *   - ScenarioTime: the adapters read time()/gmdate(), routed to the host
 *     clock by namespaced shims (WPC-3),
 *   - relayPauses() is not available before schema v8 (wave 3).
 */
final class WpHostFixture implements HostFixture {

  private DDDConfig $config;
  private string $prefix;
  private FrozenClock $clock;
  private OutboxConfig $outboxConfig;
  private WpdbTransactionBoundary $boundary;
  private WpdbOutboxStore $outbox;
  private RecordingOutboxStore $relayStore;
  private WpdbOutboxAdministration $admin;
  private ActionSchedulerTransport $transport;
  private InMemoryDeliveryLedger $ledger;
  private LedgerGatedSubscriptions $subscriptions;
  private ReentrantProcessLock $lock;
  private EventsUnitOfWork $events;
  private WpdbScenarioRows $rows;
  private WpHookDomainDispatcher $dispatcher;
  private OutboxProcessor $relay;
  private WpdbFaults $faults;
  private BufferLogger $logger;
  private bool $previousSuppress = false;

  /** @var list<IInfrastructureEvent> */
  private array $signals = [];

  /** @var list<array{0: string, 1: \Closure}> */
  private array $signalHooks = [];

  /** @var array<int, true> Action Scheduler action ids deliverTransported() already ran */
  private array $deliveredActions = [];

  private bool $up = false;

  public function hostName(): string {
    return 'wp';
  }

  public function setUp(ScenarioContext $context): void {
    global $wpdb;

    $this->resetStatics();
    $this->previousSuppress = $wpdb->suppress_errors(true);

    $this->prefix = $context->uniqueName('wp');
    $this->config = new DDDConfig($this->prefix, 'TangibleDDD\\Conformance', 'conformance');
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . \time()));
    ScenarioTime::install($this->clock);
    $this->logger = new BufferLogger();
    $this->outboxConfig = new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox'));

    // ddd-wp init, with this test's clock and logger and WITHOUT a default
    // transaction boundary or lock: the bus and the relay get theirs
    // explicitly, and cmd.no-boundary needs none anywhere.
    HostDefaults::resetForTests();
    HostDefaults::provide(IClock::class, $this->clock);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new WpHookSignalDispatcher());
    HostDefaults::provide(ISubscriberProbe::class, new HasActionSubscriberProbe());
    HostDefaults::provide(IActorProvider::class, new WpActorProvider());
    HostDefaults::provide(IEnvironmentProvider::class, new WpEnvironmentProvider());
    HostDefaults::provide(IOutboxOptionsReader::class, new WpOptionsOutboxConfigReader());
    HostDefaults::provide(IHostPortFactory::class, new WpHostPortFactory());
    HostDefaults::provide(LoggerInterface::class, $this->logger);

    install_outbox_tables($this->config);
    install_command_audit_table($this->config);
    install_touches_table($this->config);
    $this->rows = new WpdbScenarioRows($this->config->table('scenario_rows'));
    $this->rows->create();
    foreach (['integration_outbox', 'integration_dlq', 'command_audit', 'touches', 'scenario_rows'] as $table) {
      if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->config->table($table))) !== $this->config->table($table)) {
        throw new \RuntimeException("conformance-wp: table {$this->config->table($table)} was not created: {$wpdb->last_error}");
      }
    }

    $this->boundary = new WpdbTransactionBoundary(NestedPolicy::Reject);
    $this->outbox = new WpdbOutboxStore(new OutboxRepository($this->config, $this->outboxConfig), $this->config);
    $this->relayStore = new RecordingOutboxStore($this->outbox);
    $this->admin = new WpdbOutboxAdministration($this->prefix);
    $this->transport = new ActionSchedulerTransport($this->config->as_group('outbox'));
    $this->ledger = new InMemoryDeliveryLedger();
    $this->subscriptions = new LedgerGatedSubscriptions(new WpHookSubscriptionRegistry(), $this->ledger);
    $this->lock = new ReentrantProcessLock(new GetLockProcessLock(), $this->logger);
    $this->events = new EventsUnitOfWork();
    $this->dispatcher = new WpHookDomainDispatcher();
    $this->relay = new OutboxProcessor(
      $this->config, null, $this->outboxConfig, null,
      new HasActionSubscriberProbe(), $this->logger, $this->clock,
      $this->relayStore, $this->transport, $this->boundary,
    );
    $this->faults = new WpdbFaults();
    $this->faults->install();
    $this->watchSignals();

    RuntimeReset::register('conformance.events', fn () => $this->events->reset());
    RuntimeReset::guardLock($this->lock);
    $this->up = true;
  }

  public function tearDown(): void {
    global $wpdb;
    if (!$this->up) {
      return;
    }
    $this->up = false;

    $this->quietly(function () use ($wpdb): void {
      if (WpdbTransactionDepth::current() > 0) {
        $wpdb->query('ROLLBACK');
      }
    });
    $this->quietly(fn () => $this->faults->uninstall());
    $this->quietly(fn () => $this->dispatcher->unbindAll());
    $this->quietly(function (): void {
      foreach ($this->subscriptions->boundHooks() as $hook) {
        remove_all_actions($hook);
      }
    });
    $this->quietly(function (): void {
      foreach ($this->signalHooks as [$hook, $callback]) {
        remove_action($hook, $callback, 10);
      }
      $this->signalHooks = [];
    });
    $this->quietly(fn () => $this->lock->forceReleaseAll());
    $this->quietly(fn () => $this->deleteActions());
    $this->quietly(function () use ($wpdb): void {
      foreach (['integration_outbox', 'integration_dlq', 'command_audit', 'touches', 'scenario_rows'] as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$this->config->table($table)}`");
      }
      delete_option($this->config->option('outbox_pauses'));
    });

    ScenarioTime::uninstall();
    $this->resetStatics();
    HostDefaults::resetForTests();
    HostDefaultsWiring::register();
    $wpdb->suppress_errors($this->previousSuppress);
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
    return $this->admin;
  }

  public function relayPauses(): IRelayPauseStore {
    throw new \LogicException('wp has no IRelayPauseStore before schema v8 pause rows (register 8, wave 3); the 0.6 option-backed pause has no expiring holders per selector glob');
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
    $sink = $options->audit ? new WpdbAuditSink($this->config) : new NullAuditSink();

    return new CommandBus(
      new CorrelationMiddleware(
        $this->config,
        $this->events,
        new Redactor(),
        $sink,
        new WpActorProvider(),
        null,
        new WpEnvironmentProvider(),
      ),
      new TransactionalCommandMiddleware($options->withBoundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter(
          $this->dispatcher,
          new OutboxIntegrationEventBus(null, $this->config, null, $this->clock, $this->outbox, $this->outboxConfig),
        ),
      ),
      new HandlerMapMiddleware($handlers),
    );
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->dispatcher->listen($eventClassOrMarker, $listener, $priority);
  }

  public function failNextCommit(string $reason): void {
    $this->faults->failNextCommit($reason);
  }

  public function auditTrail(): array {
    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT command_id, command_name, status, error FROM `{$this->config->table('command_audit')}` WHERE status <> 'in_progress' ORDER BY id ASC"
    );
    return array_map(static function (object $row): AuditEntry {
      $error = json_decode((string) ($row->error ?? 'null'), true);
      return new AuditEntry((string) $row->command_id, (string) $row->command_name, (string) $row->status, is_array($error) ? ($error['type'] ?? null) : null);
    }, is_array($rows) ? $rows : []);
  }

  // ── relay ────────────────────────────────────────────────────────────────

  public function relayOnce(int $limit = 50): RelayReport {
    $this->relayStore->start();
    $this->relay->process_batch();
    return $this->relayStore->report();
  }

  public function rejectNextSubmission(?\Throwable $e = null): void {
    $this->transport->rejectNext($e);
  }

  public function acceptNextSubmissionWithoutRef(): void {
    $this->transport->noReferenceNext();
  }

  public function crashNextRelayAfterSubmit(): void {
    $this->relay->between_submit_and_accept(function (): void {
      $this->relay->between_submit_and_accept(null);
      throw new SimulatedCrash('relay died between submit and accept (injected)');
    });
  }

  public function transported(): array {
    return array_map(
      static fn (array $a) => new TransportedFact($a['event_id'], $a['due_at']),
      $this->actions(),
    );
  }

  public function seedLegacyDelayedFact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string {
    global $wpdb;

    // Exactly the row a 0.6 OutboxRepository::write() left: a RELATIVE
    // delay_seconds beside the absolute scheduled_at it already applied.
    $eventId = Uuid::v4();
    $payload = (string) wp_json_encode($fact->integration_payload(), JSON_UNESCAPED_SLASHES);
    $scheduled = $scheduledAt->setTimezone(new \DateTimeZone('UTC'));
    $ok = $wpdb->insert($this->config->table('integration_outbox'), [
      'event_id' => $eventId,
      'event_type' => $fact::name(),
      'integration_action' => $fact::integration_action(),
      'message_kind' => 'event',
      'transport' => 'action_scheduler',
      'queue' => $this->prefix . '-outbox',
      'payload_bytes' => strlen($payload),
      'correlation_id' => Uuid::v4(),
      'sequence' => 1,
      'command_id' => null,
      'payload' => $payload,
      'delay_seconds' => $delaySeconds,
      'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
      'is_unique' => 0,
      'status' => 'pending',
      'attempts' => 0,
      'max_attempts' => $this->outboxConfig->max_attempts,
      'created_at' => $scheduled->modify("-{$delaySeconds} seconds")->format('Y-m-d H:i:s'),
      'blog_id' => 1,
    ]);
    if ($ok === false) {
      throw new \RuntimeException("seedLegacyDelayedFact: insert failed: {$wpdb->last_error}");
    }
    return $eventId;
  }

  // ── delivery ─────────────────────────────────────────────────────────────

  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    $hook = IntegrationHookName::resolve($eventClass)
      ?? throw new \LogicException("$eventClass has no integration hook on this host");

    return $this->subscriptions->capture(static fn () => do_action($hook, $wrapped));
  }

  public function deliverTransported(string $eventClass): array {
    $outcomes = [];
    foreach ($this->actions() as $a) {
      if (isset($this->deliveredActions[$a['action_id']])) {
        continue;
      }
      $this->deliveredActions[$a['action_id']] = true;
      $outcomes[] = $this->subscriptions->capture(static fn () => \ActionScheduler::runner()->process_action($a['action_id'], 'conformance'));
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
    return null; // no ProcessRunner on the conformance wp host until wave 3
  }

  // ── audit.sink-fails seams ───────────────────────────────────────────────
  //
  // failNextAuditClose() and signals() have exactly the shapes of the
  // optional seams TangibleDDD\Conformance\AuditSinkFaults and
  // RecordsSignals that wave2/conformance-cleanup adds (CR-CC-1). Once both
  // branches are merged, add those two interfaces to this class's
  // `implements` list and the shared CommandScenarios::test_audit_sink_fails
  // runs on wp too (change request WPC-4).

  /** The next WpdbAuditSink::close() fails inside wpdb (its 0.6 finalise UPDATE throws). */
  public function failNextAuditClose(string $reason): void {
    $this->failNextAuditWrite('close', new \RuntimeException($reason));
  }

  /** The audit sink's next write fails inside wpdb: 'open' = the preflight INSERT, 'close' = the finalise UPDATE. */
  public function failNextAuditWrite(string $phase, ?\Throwable $e = null): void {
    $this->faults->throwOnNext(
      $phase === 'open' ? 'INSERT INTO' : 'UPDATE',
      $this->config->table('command_audit'),
      $e ?? new \RuntimeException("audit sink $phase failed (injected)"),
    );
  }

  /** @return list<IInfrastructureEvent> infrastructure signals emitted on this consumer's hooks since setUp(), oldest first */
  public function signals(): array {
    return $this->signals;
  }

  /** @return array<string, mixed>|null the raw command_audit row */
  public function auditRow(string $commandId): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->config->table('command_audit')}` WHERE command_id = %s", $commandId), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  public function consumer(): DDDConfig {
    return $this->config;
  }

  /**
   * What a 0.6 copy's relay would fetch right now (its own OutboxRepository
   * on the same tables; it leases what it returns, as 0.6 does).
   *
   * @return list<string> event ids
   */
  public function legacyFetchPending(): array {
    $legacy = new OutboxRepository($this->config, $this->outboxConfig);
    return array_map(static fn ($e) => $e->event_id, $legacy->fetch_pending(50, 'legacy-0.6-probe'));
  }

  /** @return list<string> */
  public function logLines(): array {
    return $this->logger->lines;
  }

  // ── internals ────────────────────────────────────────────────────────────

  /**
   * Record every infrastructure signal WpHookSignalDispatcher fires on this
   * consumer's `{prefix}_{action}` hooks, through WordPress' `all` hook
   * (it sees every do_action before the hook's own callbacks run).
   */
  private function watchSignals(): void {
    $prefix = $this->config->hook('');
    $callback = function (...$args) use ($prefix): void {
      $hook = $args[0] ?? null;
      $event = $args[1] ?? null;
      if (is_string($hook) && str_starts_with($hook, $prefix) && $event instanceof IInfrastructureEvent && $hook === $this->config->hook($event::action())) {
        $this->signals[] = $event;
      }
    };
    add_action('all', $callback, 10, 99);
    $this->signalHooks[] = ['all', $callback];
  }

  /** @return list<array{action_id: int, event_id: string, due_at: \DateTimeImmutable}> this consumer's Action Scheduler actions, oldest first */
  private function actions(): array {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT a.action_id FROM `{$wpdb->prefix}actionscheduler_actions` a
         JOIN `{$wpdb->prefix}actionscheduler_groups` g ON g.group_id = a.group_id
        WHERE g.slug = %s ORDER BY a.action_id ASC",
      $this->transport->group
    ));

    $out = [];
    foreach ($ids as $id) {
      $action = \ActionScheduler::store()->fetch_action((string) $id);
      $args = $action->get_args();
      $wrapped = is_array($args[0] ?? null) ? $args[0] : [];
      $date = $action->get_schedule()->get_date();
      $out[] = [
        'action_id' => (int) $id,
        'event_id' => (string) IntegrationEnvelope::unwrap($wrapped)->event_id,
        'due_at' => \DateTimeImmutable::createFromInterface($date ?? new \DateTime('@0'))->setTimezone(new \DateTimeZone('UTC')),
      ];
    }
    return $out;
  }

  private function deleteActions(): void {
    global $wpdb;
    $group = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM `{$wpdb->prefix}actionscheduler_groups` WHERE slug = %s", $this->transport->group));
    if ($group === null) {
      return;
    }
    $wpdb->query($wpdb->prepare(
      "DELETE l FROM `{$wpdb->prefix}actionscheduler_logs` l JOIN `{$wpdb->prefix}actionscheduler_actions` a ON a.action_id = l.action_id WHERE a.group_id = %d",
      (int) $group
    ));
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}actionscheduler_actions` WHERE group_id = %d", (int) $group));
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}actionscheduler_groups` WHERE group_id = %d", (int) $group));
  }

  private function quietly(callable $step): void {
    try {
      $step();
    } catch (\Throwable) {
      // tearDown never throws (HostFixture contract)
    }
  }

  private function resetStatics(): void {
    RuntimeReset::forgetRegistrationsForTests();
    Correlation::reset();
    Reactions::reset();
    WpdbTransactionDepth::resetForTests();
  }
}
