<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\FreshRun;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ProcessRow;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Conformance\WorkerRun;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Tests\Integration\Conformance\Support\BufferLogger;
use TangibleDDD\Tests\Integration\Conformance\Support\ConnectionSwitch;
use TangibleDDD\Tests\Integration\Conformance\Support\CountingProcessLock;
use TangibleDDD\Tests\Integration\Conformance\Support\FreshPhp;
use TangibleDDD\Tests\Integration\Conformance\Support\WpConformanceRuntime;
use TangibleDDD\Tests\Integration\Conformance\Support\WpdbFaults;
use TangibleDDD\Tests\Integration\Conformance\Support\WpProcessWorker;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;

/**
 * The wp host of the shared conformance scenarios (register section 4,
 * column wp; section 8 waves 2 and 3): WordPress 7.1.2 on MySQL 8.0, inside
 * the WP integration bootstrap, with the real Action Scheduler.
 *
 * Every port is the FINAL schema v8 wp adapter, composed by
 * Support\WpConformanceRuntime (see there for the list): fenced outbox,
 * v8 pause rows, the shipped ActionSchedulerTransport, the delivery ledger
 * behind WpLedgeredDelivery's per-callback gate, WpdbProcessStore,
 * WpdbWakeupScheduler and GetLockProcessLock. There are no fixture
 * stand-ins for ports and no clock shims: every adapter takes the host
 * IClock (WPC-1..3, shipped by wp-v8).
 *
 * Pipeline: core CorrelationMiddleware → TransactionalCommandMiddleware
 * (WpdbTransactionBoundary) → DomainEventsPublishMiddleware(EventRouter(the
 * 0.6 WordPressEventDispatcher, OutboxIntegrationEventBus over
 * WpdbOutboxStore)) → handler. Relay: the core OutboxProcessor (port form).
 * Delivery: do_action on the fact's integration hook (an Action Scheduler
 * action for relayed facts), each DDD callback gated by the ledger.
 *
 * Processes (ProcessHost): the core ProcessRunner on the v8 ports, started
 * in-band. Worker 1 is the WordPress connection; worker n > 1 is a second
 * MySQL session (its own transactions and GET_LOCK session) with its own
 * runner, lock and subscription registry over the same tables; its
 * deliveries are the core IntegrationDelivery over the same WpDeliveryLedger
 * (WordPress hooks are process-global, so a second php process's
 * add_action bindings cannot coexist in this one). drainOnce() is one
 * Action Scheduler queue pass of the consumer: the relay tick, then the
 * due actions (wakes through the ddd-wp hooks and WpWakeBracket, relayed
 * facts, redeliveries) on the host clock (WpConformanceRuntime::drainPass()).
 *
 * Fresh processes (FreshProcesses): `php bin/fresh.php` children booting
 * WordPress against the same database (Support\FreshPhp); a kill is SIGKILL.
 *
 * Isolation: the consumer prefix is the conformance facts' own prefix,
 * `ddd_conformance`, because a fact's hook, and the ledger and consumer the
 * gate derives from it, carry that prefix. setUp() and tearDown() therefore
 * wipe everything under it (tables, options, Action Scheduler actions and
 * groups, hooks) instead of using ScenarioContext::uniqueName(); setUp()
 * then installs the v8 schema fresh. Nothing is wrapped in a per-test
 * transaction. tearDown() restores ddd-wp's HostDefaults.
 */
final class WpHostFixture implements HostFixture, AuditSinkFaults, RecordsSignals, ProcessHost, FreshProcesses {

  private const WAKE_HOOKS = ['process_continue', 'await_timeout', 'ddd_wakeup'];

  private WpConformanceRuntime $rt;
  private DDDConfig $config;
  private FrozenClock $clock;
  private RecordingOutboxStore $relayStore;
  private WpdbFaults $faults;
  private BufferLogger $logger;
  private bool $previousSuppress = false;
  private bool $crashAfterSubmit = false;

  /** The id the first process of this test gets (a random base, see WpConformanceRuntime::installSchema()). */
  private int $firstProcessId = 1;

  /** @var array<int, WpProcessWorker> */
  private array $workers = [];

  /** @var array<int, ReentrantProcessLock> locks of workers n > 1 */
  private array $workerLocks = [];

  /** @var list<\wpdb> extra MySQL sessions (workers n > 1, the "elsewhere" lock holder) */
  private array $connections = [];

  private ?\wpdb $holder = null;

  /** @var list<array{0: class-string, 1: class-string}> */
  private array $starts = [];

  /** @var list<class-string> */
  private array $awaits = [];

  /** @var list<IInfrastructureEvent> */
  private array $signals = [];

  /** @var list<array{0: string, 1: \Closure, 2: int}> */
  private array $boundHooks = [];

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
    self::wipe();

    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . \time()));
    $this->logger = new BufferLogger();
    $this->rt = new WpConformanceRuntime($this->clock, $this->logger);
    $this->config = $this->rt->config;
    $this->rt->provideHostDefaults();
    $this->firstProcessId = random_int(1_000_000, 900_000_000);
    $this->rt->installSchema($this->firstProcessId);
    foreach (['integration_outbox', 'integration_dlq', 'command_audit', 'touches', 'long_processes', 'ddd_wakeups', 'ddd_delivery_ledger', 'ddd_relay_pauses', 'scenario_rows'] as $table) {
      if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->config->table($table))) !== $this->config->table($table)) {
        throw new \RuntimeException("conformance-wp: table {$this->config->table($table)} was not created: {$wpdb->last_error}");
      }
    }
    $this->rt->registerHooks();

    $this->relayStore = new RecordingOutboxStore($this->rt->outbox);
    $this->faults = new WpdbFaults();
    $this->faults->install();
    $this->watchSignals();
    $this->workers = $this->workerLocks = $this->connections = [];
    $this->starts = $this->awaits = [];
    $this->deliveredActions = [];

    RuntimeReset::register('conformance.events', fn () => $this->rt->events->reset());
    RuntimeReset::guardLock($this->rt->lock);
    // Step commands commit their effect row on the WordPress connection.
    ProcessJournal::bind($this->rt->rows, $this->rt->boundary);
    $this->up = true;
  }

  public function tearDown(): void {
    global $wpdb;
    if (!$this->up) {
      return;
    }
    $this->up = false;
    if (getenv('DDD_CONFORMANCE_DEBUG')) {
      fwrite(STDERR, "\n[conformance-wp log]\n" . implode("\n", $this->logger->lines) . "\n");
    }

    $this->quietly(function () use ($wpdb): void {
      if (WpdbTransactionDepth::current() > 0) {
        $wpdb->query('ROLLBACK');
      }
    });
    $this->quietly(fn () => $this->faults->uninstall());
    $this->quietly(fn () => $this->rt->dispatcher->unbindAll());
    $this->quietly(function (): void {
      foreach ($this->boundHooks as [$hook, $callback, $priority]) {
        remove_action($hook, $callback, $priority);
      }
      $this->boundHooks = [];
    });
    $this->quietly(fn () => $this->rt->lock->forceReleaseAll());
    foreach ($this->workerLocks as $lock) {
      $this->quietly(fn () => $lock->forceReleaseAll());
    }
    foreach ($this->connections as $db) {
      $this->quietly(fn () => $db->close());
    }
    $this->connections = [];
    $this->holder = null;
    $this->quietly(static fn () => self::wipe());

    ProcessJournal::bind(null);
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
    return $this->rt->boundary;
  }

  public function outbox(): IOutboxStore {
    return $this->rt->outbox;
  }

  public function outboxAdministration(): IOutboxAdministration {
    return $this->rt->admin;
  }

  public function relayPauses(): IRelayPauseStore {
    return $this->rt->pauses;
  }

  public function transport(): ITransport {
    return $this->rt->transport;
  }

  public function ledger(): IDeliveryLedger {
    return $this->rt->ledger;
  }

  public function subscriptions(): ISubscriptionRegistry {
    return $this->rt->subscriptions;
  }

  public function processLock(): IProcessLock {
    return $this->rt->lock;
  }

  public function events(): \TangibleDDD\Application\Events\EventsUnitOfWork {
    return $this->rt->events;
  }

  public function scenarioRows(): ScenarioRows {
    return $this->rt->rows;
  }

  // ── command pipeline ─────────────────────────────────────────────────────

  public function commandBus(array $handlers, BusOptions $options = new BusOptions()): CommandBus {
    return $this->rt->commandBus($handlers, $options);
  }

  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void {
    $this->rt->dispatcher->listen($eventClassOrMarker, $listener, $priority);
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
    $relay = $this->rt->relayProcessor($this->relayStore);
    if ($this->crashAfterSubmit) {
      $this->crashAfterSubmit = false;
      $relay->between_submit_and_accept(static function ($claim): void {
        throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept (injected)");
      });
    }
    $this->relayStore->reset();
    $relay->process_batch($limit);
    return $this->relayStore->report();
  }

  public function rejectNextSubmission(?\Throwable $e = null): void {
    $this->rt->transport->rejectNext($e);
  }

  public function acceptNextSubmissionWithoutRef(): void {
    $this->rt->transport->noReferenceNext();
  }

  public function crashNextRelayAfterSubmit(): void {
    $this->crashAfterSubmit = true;
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
      'queue' => $this->config->as_group('outbox'),
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
      'max_attempts' => $this->rt->outboxConfig->max_attempts,
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
    return $this->rt->deliver($eventClass, $wrapped);
  }

  public function deliverTransported(string $eventClass): array {
    // Only $eventClass's actions: the AS hook is the fact's integration_action.
    $hook = IntegrationHookName::resolve($eventClass)
      ?? throw new \LogicException("$eventClass has no integration hook on this host");
    $outcomes = [];
    foreach ($this->actions() as $a) {
      if ($a['hook'] !== $hook || $a['status'] !== \ActionScheduler_Store::STATUS_PENDING || isset($this->deliveredActions[$a['action_id']])) {
        continue;
      }
      $this->deliveredActions[$a['action_id']] = true;
      $outcomes[] = $this->rt->observe($eventClass, $a['event_id'], static fn () => \ActionScheduler::runner()->process_action($a['action_id'], 'conformance'));
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
    // The runner's one per-message transient (register 3.9), read without widening its API.
    return ['resume_argument' => (fn () => $this->resume_argument)->call($this->rt->runner)];
  }

  // ── audit.sink-fails seams (AuditSinkFaults, RecordsSignals) ─────────────

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

  /** The id the next process inserted into this test's fresh table gets first. */
  public function firstProcessId(): int {
    return $this->firstProcessId;
  }

  /**
   * What a 0.6 copy's relay would fetch right now (its own OutboxRepository
   * on the same tables; it leases what it returns, as 0.6 does).
   *
   * @return list<string> event ids
   */
  public function legacyFetchPending(): array {
    $legacy = new OutboxRepository($this->config, $this->rt->outboxConfig);
    return array_map(static fn ($e) => $e->event_id, $legacy->fetch_pending(50, 'legacy-0.6-probe'));
  }

  /** @return list<string> */
  public function logLines(): array {
    return $this->logger->lines;
  }

  // ── ProcessHost (CR-W3CP-1) ──────────────────────────────────────────────

  public function wireProcesses(array $starts, array $awaits): void {
    foreach ($starts as $pair) {
      $this->starts[] = $pair;
    }
    foreach ($awaits as $class) {
      $this->awaits[] = $class;
    }
    self::wire($this->rt->runner, $starts, $awaits);
    foreach ($this->workers as $n => $worker) {
      if ($n > 1) {
        self::wire($worker->processRunner(), $starts, $awaits);
      }
    }
  }

  public function worker(int $n = 1): ProcessWorker {
    if ($n < 1) {
      throw new \InvalidArgumentException("No worker $n");
    }
    return $this->workers[$n] ??= $n === 1 ? $this->firstWorker() : $this->otherWorker($n);
  }

  public function processStore(): IProcessStore {
    return $this->rt->processStore;
  }

  public function wakeups(): IWakeupScheduler {
    return $this->rt->wakeups;
  }

  /**
   * The core PortOperatorView over the wp ports (relay dead letters from
   * WpdbOutboxAdministration, stranded `running` rows from WpdbProcessStore
   * with their resume_stranded / fail_stranded repairs). The wp-specific
   * WpOperatorView does not implement IOperatorView yet (WP8-2).
   */
  public function operatorView(): IOperatorView {
    return new PortOperatorView($this->config, $this->rt->admin, $this->rt->processStore, $this->clock);
  }

  public function processConsumer(): string {
    return $this->config->prefix();
  }

  public function processLockKey(int $processId): LockKey {
    return new LockKey($this->config->prefix(), '', $processId);
  }

  public function processRow(int $id): ?ProcessRow {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, process_class, status, step_index, version, ignition_key, ignited_by_event_id FROM `{$this->config->table('long_processes')}` WHERE id = %d",
      $id
    ));
    if (!$row) {
      return null;
    }
    return new ProcessRow(
      (int) $row->id,
      (string) $row->process_class,
      (string) $row->status,
      (int) $row->step_index,
      (int) $row->version,
      $row->ignition_key === null ? null : (string) $row->ignition_key,
      $row->ignited_by_event_id === null ? null : (string) $row->ignited_by_event_id,
    );
  }

  public function processIds(?string $processClass = null): array {
    global $wpdb;
    $table = $this->config->table('long_processes');
    $ids = $processClass === null
      ? $wpdb->get_col("SELECT id FROM `$table` ORDER BY id ASC")
      : $wpdb->get_col($wpdb->prepare("SELECT id FROM `$table` WHERE process_class = %s ORDER BY id ASC", $processClass));
    return array_map('intval', is_array($ids) ? $ids : []);
  }

  /** Intents not done or cancelled (pending, firing, exhausted), in scheduling order. */
  public function pendingWakeups(): array {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM `{$this->config->table('ddd_wakeups')}` WHERE status IN ('pending', 'firing', 'exhausted') ORDER BY id ASC");
    return array_map(fn (object $row): WakeupIntent => $this->rt->wakeups->intent($row), is_array($rows) ? $rows : []);
  }

  /** Another MySQL session takes both names GetLockProcessLock takes (the namespaced one and `ddd_process_<id>`). */
  public function holdProcessLockElsewhere(int $processId): void {
    $db = $this->holder();
    $key = $this->processLockKey($processId);
    $taken = [];
    foreach ([GetLockProcessLock::name($key), GetLockProcessLock::legacyName($key)] as $name) {
      if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
        foreach ($taken as $held) {
          $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $held));
        }
        throw new \RuntimeException("conformance-wp: the other session could not take $name (held by another run on this MySQL server?)");
      }
      $taken[] = $name;
    }
  }

  public function releaseProcessLockElsewhere(int $processId): void {
    $db = $this->holder();
    $key = $this->processLockKey($processId);
    foreach ([GetLockProcessLock::legacyName($key), GetLockProcessLock::name($key)] as $name) {
      $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
  }

  /** The next per-process GET_LOCK answers NULL at the driver (Support\WpdbFaults). */
  public function failNextProcessLockAcquire(string $reason): void {
    $this->faults->nullNextProcessLock();
  }

  public function processLockAcquisitions(): int {
    return (int) $this->rt->lockAcquisitions['n'];
  }

  public function beforeNextProcessLockAcquire(callable $fn): void {
    $this->rt->interleaving->beforeNextAcquire(static function () use ($fn): void {
      $fn();
    });
  }

  /**
   * wp hands an intent to its wake transport when Action Scheduler runs the
   * projected action. The next such hand-off fails before any wake callback
   * runs: a callback ahead of ddd-wp's on the three wake hooks throws, so
   * the action fails, WpWakeBracket never begins and the intent row stays
   * `pending`; the relay tick re-projects it once its action is gone
   * (register 5.3 step 3, wp form). A refused projection at schedule time
   * cannot be expressed instead: WpdbWakeupScheduler::schedule() then
   * throws and the state change rolls back with it, by design.
   */
  public function failNextWakeHandoff(string $reason): void {
    $hooks = array_map(fn (string $h) => $this->config->hook($h), self::WAKE_HOOKS);
    $fault = null;
    $fault = function () use (&$fault, $hooks, $reason): void {
      foreach ($hooks as $hook) {
        remove_action($hook, $fault, 0);
      }
      throw new \RuntimeException("wake transport unavailable: $reason (injected)");
    };
    foreach ($hooks as $hook) {
      add_action($hook, $fault, 0, 0);
      $this->boundHooks[] = [$hook, $fault, 0];
    }
  }

  // ── FreshProcesses (CR-W3CP-4) ───────────────────────────────────────────

  public function publishInFreshProcess(DomainEvent&IIntegrationEvent $fact, bool $killAfterCommit): string {
    $run = FreshPhp::run('publish', ['fact' => base64_encode(serialize($fact)), 'kill' => $killAfterCommit], $this->clock->now());
    $id = $run['out']['eventId'] ?? null;
    if (!is_string($id) || $id === '') {
      throw new \RuntimeException('conformance-wp: the fresh process published nothing: ' . json_encode($run['out']) . "\n" . $run['stderr']);
    }
    return $id;
  }

  public function drainInFreshProcess(): FreshRun {
    $run = FreshPhp::run('drain', [], $this->clock->now());
    return new FreshRun(
      died: $run['died'],
      relayed: array_values((array) ($run['out']['relayed'] ?? [])),
      delivered: (int) ($run['out']['delivered'] ?? 0),
      errors: array_values((array) ($run['out']['errors'] ?? [])),
    );
  }

  public function deliverInFreshProcess(string $eventClass, array $wrapped): FreshRun {
    $run = FreshPhp::run('deliver', ['eventClass' => $eventClass, 'wrapped' => $wrapped], $this->clock->now());
    return new FreshRun(
      died: $run['died'],
      delivered: (int) ($run['out']['delivered'] ?? 0),
      errors: array_values((array) ($run['out']['errors'] ?? [])),
    );
  }

  public function startInFreshProcess(LongProcess $process, ?string $dieAfterCommand = null): FreshRun {
    $run = FreshPhp::run('start', ['process' => base64_encode(serialize($process)), 'die' => $dieAfterCommand], $this->clock->now());
    $id = $run['out']['processId'] ?? null;
    return new FreshRun(
      died: $run['died'],
      processId: $id === null ? null : (int) $id,
      errors: array_values((array) ($run['out']['errors'] ?? [])),
    );
  }

  // ── internals ────────────────────────────────────────────────────────────

  private function firstWorker(): WpProcessWorker {
    return new WpProcessWorker(
      $this->rt->runner,
      $this->rt->lock,
      fn (string $eventClass, array $wrapped): DeliveryOutcome => $this->rt->deliver($eventClass, $wrapped),
      fn (int $maxItems) => $this->rt->drainPass($this->rt->runner, $maxItems)['report'],
    );
  }

  /** Worker n > 1: another MySQL session with its own runner, lock session and registry over the same tables. */
  private function otherWorker(int $n): WpProcessWorker {
    global $wpdb;
    $db = ConnectionSwitch::open($wpdb);
    $this->connections[] = $db;
    $registry = new SubscriptionRegistry();
    $lock = new ReentrantProcessLock(new CountingProcessLock(new GetLockProcessLock(), $this->rt->lockAcquisitions, $db), $this->logger);
    $this->workerLocks[$n] = $lock;
    $runner = $this->rt->runnerOn($lock, $registry);
    self::wire($runner, $this->starts, $this->awaits);

    return new WpProcessWorker(
      $runner,
      $lock,
      fn (string $eventClass, array $wrapped): DeliveryOutcome => ConnectionSwitch::on($db, fn () => (new IntegrationDelivery(
        $registry, $this->rt->ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger,
      ))->deliver($eventClass, WpConformanceRuntime::onTheWire($wrapped))),
      fn (int $maxItems) => ConnectionSwitch::on($db, fn () => $this->rt->drainPass($runner, $maxItems)['report']),
    );
  }

  private function holder(): \wpdb {
    global $wpdb;
    if ($this->holder === null) {
      $this->holder = ConnectionSwitch::open($wpdb);
      $this->connections[] = $this->holder;
    }
    return $this->holder;
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

  /**
   * Record every infrastructure signal WpHookSignalDispatcher fires on this
   * consumer's `{prefix}_{action}` hooks, through WordPress' `all` hook
   * (it sees every do_action before the hook's own callbacks run).
   */
  private function watchSignals(): void {
    $this->signals = [];
    $prefix = $this->config->hook('');
    $callback = function (...$args) use ($prefix): void {
      $hook = $args[0] ?? null;
      $event = $args[1] ?? null;
      if (is_string($hook) && str_starts_with($hook, $prefix) && $event instanceof IInfrastructureEvent && $hook === $this->config->hook($event::action())) {
        $this->signals[] = $event;
      }
    };
    add_action('all', $callback, 10, 99);
    $this->boundHooks[] = ['all', $callback, 10];
  }

  /**
   * This consumer's relayed facts in Action Scheduler (the outbox group's
   * integration-hook actions; redeliveries share the group and are left
   * out), oldest first, any status.
   *
   * @return list<array{action_id: int, hook: string, status: string, event_id: string, due_at: \DateTimeImmutable}>
   */
  private function actions(): array {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT a.action_id FROM `{$wpdb->prefix}actionscheduler_actions` a
         JOIN `{$wpdb->prefix}actionscheduler_groups` g ON g.group_id = a.group_id
        WHERE g.slug = %s AND a.hook LIKE %s ORDER BY a.action_id ASC",
      $this->rt->transport->group,
      '%' . $wpdb->esc_like('_integration_') . '%'
    ));

    $out = [];
    foreach (is_array($ids) ? $ids : [] as $id) {
      $action = \ActionScheduler::store()->fetch_action((string) $id);
      $date = $action->get_schedule()->get_date();
      $out[] = [
        'action_id' => (int) $id,
        'hook' => (string) $action->get_hook(),
        'status' => (string) \ActionScheduler::store()->get_status((string) $id),
        'event_id' => WpConformanceRuntime::eventIdOfArgs($action->get_args()),
        'due_at' => \DateTimeImmutable::createFromInterface($date ?? new \DateTime('@0'))->setTimezone(new \DateTimeZone('UTC')),
      ];
    }
    return $out;
  }

  /**
   * Remove everything the conformance consumer can leave behind: its
   * tables, options, Action Scheduler actions (with logs) and groups, and
   * the callbacks bound on its hooks.
   */
  private static function wipe(): void {
    global $wpdb, $wp_filter;
    $prefix = WpConformanceRuntime::PREFIX . '_';

    foreach (array_keys((array) $wp_filter) as $hook) {
      if (str_starts_with((string) $hook, $prefix)) {
        remove_all_actions((string) $hook);
      }
    }

    foreach ((array) $wpdb->get_col($wpdb->prepare(
      'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
      $wpdb->esc_like($wpdb->prefix . $prefix) . '%'
    )) as $table) {
      $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
    wp_cache_flush();

    $like = $wpdb->esc_like($prefix) . '%';
    $groupLike = $wpdb->esc_like(WpConformanceRuntime::PREFIX . '-') . '%';
    $wpdb->query($wpdb->prepare(
      "DELETE l FROM `{$wpdb->prefix}actionscheduler_logs` l JOIN `{$wpdb->prefix}actionscheduler_actions` a ON a.action_id = l.action_id WHERE a.hook LIKE %s",
      $like
    ));
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}actionscheduler_actions` WHERE hook LIKE %s", $like));
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}actionscheduler_groups` WHERE slug LIKE %s", $groupLike));
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
    WpLedgeredDelivery::resetForTests();
    IntegrationHookName::reset();
  }
}
