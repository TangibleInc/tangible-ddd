<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use League\Tactician\CommandBus;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\Support\HandlerMapMiddleware;
use TangibleDDD\Conformance\Support\InterleavingProcessLock;
use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\WordPress\Adapter\ActionSchedulerTransport;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\HasActionSubscriberProbe;
use TangibleDDD\WordPress\Adapter\WpActorProvider;
use TangibleDDD\WordPress\Adapter\WpdbAuditSink;
use TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbWakeupScheduler;
use TangibleDDD\WordPress\Adapter\WpDeliveryLedger;
use TangibleDDD\WordPress\Adapter\WpEnvironmentProvider;
use TangibleDDD\WordPress\Adapter\WpHookSignalDispatcher;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;
use TangibleDDD\WordPress\Adapter\WpHostPortFactory;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpOptionsOutboxConfigReader;
use TangibleDDD\WordPress\Adapter\WpRelayPauseStore;
use TangibleDDD\WordPress\Adapter\WpRelayTick;
use TangibleDDD\WordPress\Adapter\WpStrandedScan;

use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\install_tables;
use function TangibleDDD\WordPress\register_delivery_hooks;
use function TangibleDDD\WordPress\register_process_hooks;

/**
 * The conformance consumer composed on the FINAL (schema v8) wp adapters,
 * shared by WpHostFixture (the test process) and bin/fresh.php (every
 * fresh php process), so both boot the same way production does.
 *
 *   boundary              WpdbTransactionBoundary (checked, NestedPolicy::Reject)
 *   outbox                WpdbOutboxStore (claim_token fencing, requested lease, host clock)
 *   outboxAdministration  WpdbOutboxAdministration (host clock)
 *   relayPauses           WpRelayPauseStore (v8 pause rows + the 0.6 option)
 *   transport             ActionSchedulerTransport (+ the two fault seams, FaultingTransport)
 *   ledger                WpDeliveryLedger, the one WpLedgeredDelivery gates every
 *                         DDD-registered callback through
 *   subscriptions         WpHookSubscriptionRegistry (one add_action per subscriber)
 *   processStore          WpdbProcessStore (ignition_key, version fencing, stranded scan)
 *   wakeups               WpdbWakeupScheduler (intent rows + AS projection at schedule time)
 *   processLock           ReentrantProcessLock over GetLockProcessLock (both names)
 *   runner                the core ProcessRunner on those ports, StartMode::InBand
 *   wake path             the ddd-wp Action Scheduler hooks (register_process_hooks:
 *                         process_continue, await_timeout, ddd_wakeup → WpWakeBracket)
 *
 * The consumer prefix is the conformance facts' own prefix, `ddd_conformance`
 * (IntegrationBehaviour::prefix()): the integration hooks, the ledger the
 * gate picks for a hook, the Action Scheduler groups and every table share
 * it, exactly as a real consumer and its facts do. Isolation per test is a
 * wipe of everything under that prefix (WpHostFixture), not a unique name.
 */
final class WpConformanceRuntime {

  public const PREFIX = 'ddd_conformance';

  public readonly DDDConfig $config;
  public readonly OutboxConfig $outboxConfig;
  public readonly WpdbTransactionBoundary $boundary;
  public readonly WpRelayPauseStore $pauses;
  public readonly WpdbOutboxStore $outbox;
  public readonly WpdbOutboxAdministration $admin;
  public readonly FaultingTransport $transport;
  public readonly WpDeliveryLedger $ledger;
  public readonly WpHookSubscriptionRegistry $subscriptions;
  /** successful backend lock acquisitions, all workers ('n') */
  public readonly \ArrayObject $lockAcquisitions;
  public readonly InterleavingProcessLock $interleaving;
  public readonly ReentrantProcessLock $lock;
  public readonly EventsUnitOfWork $events;
  public readonly WpdbScenarioRows $rows;
  public readonly WpHookDomainDispatcher $dispatcher;
  public readonly ProcessRepository $processRepository;
  public readonly WpdbProcessStore $processStore;
  public readonly WpdbWakeupScheduler $wakeups;
  public readonly ProcessRunner $runner;

  /** The runner the Action Scheduler wake hooks resolve (the worker draining right now). */
  private ?ProcessRunner $wakeRunner = null;

  public function __construct(public readonly IClock $clock, public readonly BufferLogger $logger) {
    $this->config = new DDDConfig(self::PREFIX, 'TangibleDDD\\Conformance', 'conformance');
    $this->outboxConfig = new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox'));
    $this->boundary = new WpdbTransactionBoundary(NestedPolicy::Reject);
    $this->pauses = new WpRelayPauseStore($this->config, $clock);
    $this->outbox = new WpdbOutboxStore(new \TangibleDDD\Infra\Persistence\OutboxRepository($this->config, $this->outboxConfig), $this->config, $clock, $this->pauses);
    $this->admin = new WpdbOutboxAdministration(self::PREFIX, $clock);
    $this->transport = new FaultingTransport(new ActionSchedulerTransport($this->config->as_group('outbox')), $this->config->as_group('outbox'));
    $this->ledger = new WpDeliveryLedger(self::PREFIX, $clock);
    $this->subscriptions = new WpHookSubscriptionRegistry();
    $this->lockAcquisitions = new \ArrayObject(['n' => 0]);
    $this->interleaving = new InterleavingProcessLock(new CountingProcessLock(new GetLockProcessLock(), $this->lockAcquisitions));
    $this->lock = new ReentrantProcessLock($this->interleaving, $logger);
    $this->events = new EventsUnitOfWork();
    $this->rows = new WpdbScenarioRows($this->config->table('scenario_rows'));
    $this->dispatcher = new WpHookDomainDispatcher();
    $this->processRepository = new ProcessRepository($this->config);
    $this->processStore = new WpdbProcessStore($this->processRepository, $this->config, $clock);
    $this->wakeups = new WpdbWakeupScheduler($this->config, $clock);
    $this->runner = $this->runnerOn($this->lock, $this->subscriptions);
  }

  /** A ProcessRunner on this consumer's ports with its own lock and subscription registry (another worker). */
  public function runnerOn(\TangibleDDD\Runtime\Lock\IProcessLock $lock, \TangibleDDD\Runtime\Delivery\ISubscriptionRegistry $registry): ProcessRunner {
    return new ProcessRunner(
      $this->config, null, $lock, $this->processStore, $this->wakeups, $registry,
      $this->boundary, $this->clock, StartMode::InBand, $this->logger,
    );
  }

  /** The schema v8 tables of the consumer and its schema version option, as a migrated install has them. */
  public function installSchema(): void {
    install_tables($this->config);
    update_option(ddd_schema_version_key($this->config), 8, false);
    $this->rows->create();
  }

  /**
   * ddd-wp init with this runtime's clock and logger and WITHOUT a default
   * transaction boundary or lock: the bus, the relay and the runner get
   * theirs explicitly, and cmd.no-boundary needs none anywhere.
   */
  public function provideHostDefaults(): void {
    HostDefaults::resetForTests();
    HostDefaults::provide(IClock::class, $this->clock);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new WpHookSignalDispatcher());
    HostDefaults::provide(ISubscriberProbe::class, new HasActionSubscriberProbe());
    HostDefaults::provide(IActorProvider::class, new WpActorProvider());
    HostDefaults::provide(IEnvironmentProvider::class, new WpEnvironmentProvider());
    HostDefaults::provide(IOutboxOptionsReader::class, new WpOptionsOutboxConfigReader());
    HostDefaults::provide(IHostPortFactory::class, new WpHostPortFactory());
    HostDefaults::provide(LoggerInterface::class, $this->logger);
  }

  /**
   * What register_hooks() adds for a consumer on its delivery and wake
   * side: the Action Scheduler wake hooks (bracketed by WpWakeBracket; the
   * container they resolve the runner from answers the worker that is
   * draining) and the `{prefix}_ddd_redeliver` hook.
   */
  public function registerHooks(): void {
    $container = new class($this) {
      public function __construct(private readonly WpConformanceRuntime $rt) {}

      public function get(string $id): object {
        return $this->rt->wakeRunner();
      }

      public function has(string $id): bool {
        return $id === ProcessRunner::class;
      }
    };
    register_process_hooks($this->config, static fn () => $container);
    register_delivery_hooks($this->config);
    WpLedgeredDelivery::registerConsumer($this->config);
  }

  public function wakeRunner(): ProcessRunner {
    return $this->wakeRunner ?? $this->runner;
  }

  // ── command pipeline ─────────────────────────────────────────────────────

  /** @param array<class-string, callable(object): mixed> $handlers */
  public function commandBus(array $handlers, BusOptions $options = new BusOptions()): CommandBus {
    $sink = $options->audit ? new WpdbAuditSink($this->config) : new NullAuditSink();

    return new CommandBus(
      new CorrelationMiddleware($this->config, $this->events, new Redactor(), $sink, new WpActorProvider(), null, new WpEnvironmentProvider()),
      new TransactionalCommandMiddleware($options->withBoundary ? $this->boundary : null),
      new DomainEventsPublishMiddleware(
        $this->events,
        new EventRouter($this->dispatcher, new OutboxIntegrationEventBus(null, $this->config, null, $this->clock, $this->outbox, $this->outboxConfig)),
      ),
      new HandlerMapMiddleware($handlers),
    );
  }

  /** The core relay step in its port form (what WpRelayTick runs on a v8 consumer). */
  public function relayProcessor(IOutboxStore $store): OutboxProcessor {
    return new OutboxProcessor(
      $this->config, null, $this->outboxConfig, null,
      new HasActionSubscriberProbe(), $this->logger, $this->clock,
      $store, $this->transport, $this->boundary,
    );
  }

  // ── delivery ─────────────────────────────────────────────────────────────

  /**
   * One delivery on the fact's integration hook (do_action), through the
   * ledger gate of WpLedgeredDelivery.
   *
   * The wire form is the wp one: a relayed envelope always carries the
   * outbox row's UUID correlation id, and the 0.6 `long_processes`
   * column a fact-ignited process inherits it into is CHAR(36). A
   * hand-built envelope whose correlation id is not a UUID (the shared
   * scenarios use `corr-{event id}`) is given its deterministic uuid5, so
   * the same input always maps to the same story (request W3-WPC3-2).
   */
  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    $hook = IntegrationHookName::resolve($eventClass)
      ?? throw new \LogicException("$eventClass has no integration hook on this host");
    $wrapped = self::onTheWire($wrapped);

    return $this->observe($eventClass, (string) ($wrapped['__event_id'] ?? ''), static fn () => do_action($hook, $wrapped));
  }

  /** @param array<string, mixed> $wrapped */
  public static function onTheWire(array $wrapped): array {
    $correlation = $wrapped['__correlation_id'] ?? null;
    if (is_string($correlation) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $correlation) !== 1) {
      $wrapped['__correlation_id'] = \TangibleDDD\Domain\Shared\Uuid::v5(self::CORRELATION_NAMESPACE, $correlation);
    }
    return $wrapped;
  }

  /** uuid5 namespace for hand-built correlation ids (the RFC 4122 URL namespace). */
  private const CORRELATION_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

  /**
   * Run $fire (a do_action or an Action Scheduler action of one fact) and
   * read what the gate did per subscriber of $eventClass from the ledger,
   * as a core DeliveryOutcome: delivered before → skipped; exhausted
   * (before or now) → exhausted; delivered now → delivered; otherwise it
   * threw (or its compensation is still pending) → failed. Order: the
   * registry's (priority, then registration).
   */
  public function observe(string $eventClass, string $eventId, callable $fire): DeliveryOutcome {
    $ids = array_map(static fn ($s) => $s->id, $this->subscriptions->for($eventClass));
    $before = [];
    foreach ($ids as $id) {
      $before[$id] = $eventId === '' ? [false, false] : [$this->ledger->delivered($id, $eventId), $this->ledger->exhausted($id, $eventId)];
    }

    $fire();

    $out = ['delivered' => [], 'skipped' => [], 'failed' => [], 'exhausted' => []];
    foreach ($ids as $id) {
      if ($eventId === '') {
        $out['delivered'][] = $id;
        continue;
      }
      [$wasDelivered, $wasExhausted] = $before[$id];
      $list = match (true) {
        $wasDelivered => 'skipped',
        $wasExhausted, $this->ledger->exhausted($id, $eventId) => 'exhausted',
        $this->ledger->delivered($id, $eventId) => 'delivered',
        default => 'failed',
      };
      $out[$list][] = $id;
    }
    return new DeliveryOutcome($out['delivered'], $out['skipped'], $out['failed'], $out['exhausted']);
  }

  // ── the wp worker pass ───────────────────────────────────────────────────

  /**
   * One wp worker pass, the Drain::runOnce() of this host: what one Action
   * Scheduler queue run does for the consumer.
   *
   * 1. The relay tick (WpRelayTick: the port-form relay, re-projection of
   *    due intents whose AS action is gone, restored redeliveries, the wp
   *    stranded scan), as the recurring `{prefix}_outbox_process` action.
   * 2. The consumer's due Action Scheduler actions, claimed once at the
   *    start of the step, in schedule order, each run with the AS runner:
   *    wakes (process_continue, await_timeout, ddd_wakeup, with $runner as
   *    the runner they resolve), relayed facts (the delivery stage) and
   *    redeliveries. "Due" is the HOST clock (async actions are always due),
   *    so advanceClock() moves Action Scheduler time too. RuntimeReset runs
   *    between actions, as between Messenger messages.
   *
   * The DrainReport is read back from the intent table: keys that became
   * `done` are completed, keys that spent an attempt are retried (and
   * exhausted at the budget).
   *
   * @return array{report: DrainReport, relayed: list<string>}
   */
  public function drainPass(ProcessRunner $runner, int $maxItems = 200): array {
    $before = $this->intentStates();
    $logged = count($this->logger->lines);
    $errors = [];
    $leaks = [];
    $delivered = 0;
    $items = 0;

    $failed = static function (int $actionId, \Throwable $e) use (&$errors): void {
      $errors[] = "action $actionId failed: " . $e->getMessage();
    };
    add_action('action_scheduler_failed_execution', $failed, 10, 2);
    $previous = $this->wakeRunner;
    $this->wakeRunner = $runner;
    $recording = new RecordingOutboxStore($this->outbox);
    try {
      $tick = (new WpRelayTick(
        $this->config, $this->relayProcessor($recording), true, $this->wakeups,
        new WpStrandedScan($this->config, $this->processStore, $this->wakeups, $this->clock),
        $this->clock, true,
      ))->run();
      foreach ($tick->errors as $step => $message) {
        $errors[] = "relay tick $step: $message";
      }
      $items += count($recording->report()->claimed);

      foreach ($this->dueActions($maxItems) as [$actionId, $hook]) {
        \ActionScheduler::runner()->process_action($actionId, 'ddd-conformance');
        $items++;
        if (str_contains($hook, '_integration_')) {
          $delivered++;
        }
        try {
          RuntimeReset::betweenMessages();
        } catch (RuntimeLeakDetected $l) {
          $leaks[] = $l->getMessage();
        }
      }
    } finally {
      $this->wakeRunner = $previous;
      remove_action('action_scheduler_failed_execution', $failed, 10);
    }

    foreach (array_slice($this->logger->lines, $logged) as $line) {
      if (str_contains($line, '[ddd delivery]') && (str_contains($line, ' failed on ') || str_contains($line, 'ledger failure'))) {
        $errors[] = $line;
      }
    }

    $completed = $retried = $exhausted = [];
    foreach ($this->intentStates() as $key => [$status, $attempts]) {
      [$was, $wasAttempts] = $before[$key] ?? ['new', 0];
      if ($status === 'done' && $was !== 'done') {
        $completed[] = $key;
      } elseif ($attempts > $wasAttempts) {
        $retried[] = $key;
        if ($status === 'exhausted') {
          $exhausted[] = $key;
        }
      }
    }

    return [
      'report' => new DrainReport(
        relay: $tick->relay,
        delivered: $delivered,
        wakesCompleted: $completed,
        wakesRetried: $retried,
        wakesExhausted: $exhausted,
        items: $items,
        stoppedBy: $items >= $maxItems ? DrainReport::STOPPED_MAX_ITEMS : DrainReport::STOPPED_IDLE,
        leaks: $leaks,
        errors: $errors,
      ),
      'relayed' => $recording->report()->accepted,
    ];
  }

  /**
   * The consumer's pending Action Scheduler actions due on the host clock,
   * oldest first (async actions are always due).
   *
   * @return list<array{0: int, 1: string}> [action id, hook]
   */
  public function dueActions(int $limit = 200): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT action_id, hook FROM `{$wpdb->prefix}actionscheduler_actions`
        WHERE status = 'pending' AND hook LIKE %s ORDER BY scheduled_date_gmt ASC, action_id ASC",
      $wpdb->esc_like(self::PREFIX . '_') . '%'
    ));
    $now = $this->clock->now()->getTimestamp();
    $due = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      $action = \ActionScheduler::store()->fetch_action((string) $row->action_id);
      $schedule = $action->get_schedule();
      $at = $schedule instanceof \ActionScheduler_NullSchedule ? null : $schedule->get_date()?->getTimestamp();
      if ($at === null || $at <= $now) {
        $due[] = [(int) $row->action_id, (string) $row->hook];
      }
      if (count($due) >= $limit) {
        break;
      }
    }
    return $due;
  }

  /** @return array<string, array{0: string, 1: int}> intent key => [status, attempts] */
  public function intentStates(): array {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT idempotency_key, status, attempts FROM `{$this->config->table('ddd_wakeups')}`");
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      $out[(string) $row->idempotency_key] = [(string) $row->status, (int) $row->attempts];
    }
    return $out;
  }

  /** The event id inside an Action Scheduler action's [wrapped envelope] args ('' when there is none). */
  public static function eventIdOfArgs(array $args): string {
    $wrapped = is_array($args[0] ?? null) ? $args[0] : null;
    return $wrapped === null ? '' : (string) IntegrationEnvelope::unwrap($wrapped)->event_id;
  }
}
