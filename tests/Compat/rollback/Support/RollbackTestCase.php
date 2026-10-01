<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbConsumer;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbJournal;
use TangibleDDD\WordPress\Adapter\ActionSchedulerTransport;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\HasActionSubscriberProbe;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;
use TangibleDDD\WordPress\Adapter\WpdbWakeupScheduler;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpRelayTick;
use TangibleDDD\WordPress\Adapter\WpRelayTickReport;
use TangibleDDD\WordPress\Adapter\WpStrandedScan;

use function TangibleDDD\WordPress\ddd_maybe_migrate;
use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\install_tables;
use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\register_delivery_hooks;
use function TangibleDDD\WordPress\register_process_hooks;

/**
 * Base of the register 7.3 cases: the rollback consumer `ddd_rb` composed
 * on N's schema v8 wp adapters in THIS process (what N's winner runs), and
 * the legacy winners as children (Legacy) on the same database.
 *
 * Time is the wall clock on both sides (a 0.6 copy has no IClock). "Later"
 * is made by ageing rows and Action Scheduler actions (age()).
 *
 * Isolation: everything under the `ddd_rb` prefix (tables, options, Action
 * Scheduler actions, logs and groups, hooks) and the journal table is wiped
 * before and after each test; process ids start at a random base, because
 * the legacy lock name `ddd_process_<id>` is global to the MySQL server.
 */
abstract class RollbackTestCase extends TestCase {

  protected DDDConfig $config;

  protected \wpdb $wpdb;

  protected ?ProcessRunner $runner = null;

  protected WpdbOutboxStore $outbox;

  protected WpdbProcessStore $processStore;

  protected WpdbWakeupScheduler $wakeups;

  protected OutboxConfig $outboxConfig;

  /** @return iterable<string, array{0: string}> one case per installed legacy copy */
  public static function legacyVersions(): iterable {
    $all = Legacy::all();
    if ($all === []) {
      // A data provider cannot fail the run; the case then fails on a sentinel.
      yield 'DDD_ROLLBACK_LEGACY unset' => ['none'];
      return;
    }
    foreach (array_keys($all) as $version) {
      yield "L-$version" => [$version];
    }
  }

  protected function legacy(string $version): Legacy {
    $all = Legacy::all();
    if (!isset($all[$version])) {
      self::fail("No legacy copy $version installed: tests/harness/run.sh compat passes DDD_ROLLBACK_LEGACY=\"<version>=<dir> ...\" (got '" . (string) getenv('DDD_ROLLBACK_LEGACY') . "')");
    }
    return $all[$version];
  }

  protected function setUp(): void {
    parent::setUp();
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->config = new DDDConfig(RbConsumer::PREFIX, RbConsumer::NAMESPACE_ROOT, 'n');
    $this->outboxConfig = new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox'));
    $this->resetStatics();
    ConsumerRegistry::add($this->config, static fn () => null, 'rollback fixtures', RbConsumer::NAMESPACE_ROOT);
    $this->wipe();
    RbJournal::create();
  }

  protected function tearDown(): void {
    $this->wipe();
    RbJournal::drop();
    $this->resetStatics();
    parent::tearDown();
  }

  // ── N's side ─────────────────────────────────────────────────────────────

  /** A fresh N install: schema v8, as an activation leaves it. */
  protected function nInstall(): void {
    if (!function_exists('dbDelta')) {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    install_tables($this->config);
    update_option(ddd_schema_version_key($this->config), 8, false);
    $this->idBase();
  }

  /** The upgrade: N's migration over whatever schema a legacy winner installed (v8 explicit migration, backfills). */
  protected function nUpgrade(): void {
    ddd_maybe_migrate($this->config);
    self::assertSame(8, (int) get_option(ddd_schema_version_key($this->config)), 'N migrated the consumer to v8: ' . (string) get_option($this->config->option('ddd_migration_error')));
  }

  /** N boots as the winner: its v8 ports, its process runner and hooks, the fixtures' listener. */
  protected function nBoot(): void {
    $clock = new SystemClock();
    $this->outbox = new WpdbOutboxStore(new OutboxRepository($this->config, $this->outboxConfig), $this->config, $clock);
    $this->processStore = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $clock);
    $this->wakeups = new WpdbWakeupScheduler($this->config, $clock);
    $this->runner = new ProcessRunner(
      $this->config, null, new ReentrantProcessLock(new GetLockProcessLock()), $this->processStore, $this->wakeups,
      new WpHookSubscriptionRegistry(), new WpdbTransactionBoundary(NestedPolicy::Reject), $clock, StartMode::InBand,
    );
    RbConsumer::wire($this->runner);
    $runner = $this->runner;
    $container = new class($runner) {
      public function __construct(private readonly ProcessRunner $runner) {}

      public function get(string $id): object {
        return $this->runner;
      }

      public function has(string $id): bool {
        return $id === ProcessRunner::class;
      }
    };
    register_process_hooks($this->config, static fn () => $container);
    register_delivery_hooks($this->config);
    // The recurring `{prefix}_outbox_process` action: one relay tick, as N's
    // register_outbox_hooks() runs it on a v8 consumer (WpRelayTick).
    add_action($this->config->hook('outbox_process'), fn () => $this->nTick());
    RbConsumer::listen(static fn (string $class, callable $fn) => integration_action($class, $fn));
  }

  /** N's publish path: one outbox row per fact (what the port-form bus appends at commit). @return list<string> event ids */
  protected function nPublish(IIntegrationEvent ...$facts): array {
    $ids = [];
    foreach ($facts as $fact) {
      $id = Uuid::v4();
      (new WpdbTransactionBoundary(NestedPolicy::Reject))->run(fn () => $this->outbox->append(new OutboxRecord(
        $id, $fact::name(), $fact::integration_action(), Uuid::v4(), 1, null, $fact->integration_payload(),
        (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $fact->delay() . ' seconds'),
        $fact->is_unique(), $fact->is_unique() ? $fact->integration_payload() : null, $this->outboxConfig->max_attempts,
      )));
      $ids[] = $id;
    }
    return $ids;
  }

  protected function nStart(LongProcess $process): int {
    $this->runner->start($process);
    return (int) $this->wpdb->get_var("SELECT MAX(id) FROM `{$this->table('long_processes')}`");
  }

  /** One N relay tick (port-form relay, re-projection, restored redeliveries, stranded scan). */
  protected function nTick(): WpRelayTickReport {
    $relay = new OutboxProcessor(
      $this->config, null, $this->outboxConfig, null, new HasActionSubscriberProbe(), null, new SystemClock(),
      $this->outbox, new ActionSchedulerTransport($this->config->as_group('outbox')), new WpdbTransactionBoundary(NestedPolicy::Reject),
    );
    return (new WpRelayTick(
      $this->config, $relay, true, $this->wakeups,
      new WpStrandedScan($this->config, $this->processStore, $this->wakeups, new SystemClock()),
      new SystemClock(), true,
    ))->run();
  }

  /**
   * One N queue pass: the relay tick, then every due pending action of the
   * consumer (async = due), round after round.
   *
   * @return array{ran: list<string>, failed: list<string>}
   */
  protected function nDrain(int $rounds = 5): array {
    $this->nTick();
    $failed = [];
    $listener = static function ($id, $e) use (&$failed): void {
      $failed[] = "$id: " . $e->getMessage();
    };
    add_action('action_scheduler_failed_execution', $listener, 10, 2);
    $ran = [];
    $seen = [];
    try {
      for ($round = 0; $round < $rounds; $round++) {
        $fresh = array_values(array_filter($this->dueActions(), static fn (array $a) => !isset($seen[$a[0]])));
        if ($fresh === []) {
          break;
        }
        foreach ($fresh as [$id, $hook]) {
          $seen[$id] = true;
          \ActionScheduler::runner()->process_action($id, 'ddd-rollback-n');
          $ran[] = $hook;
          RuntimeReset::between_messages();
        }
      }
    } finally {
      remove_action('action_scheduler_failed_execution', $listener, 10);
    }
    return ['ran' => $ran, 'failed' => $failed];
  }

  // ── shared state ─────────────────────────────────────────────────────────

  protected function table(string $name): string {
    return $this->config->table($name);
  }

  /** @return list<array<string, mixed>> */
  protected function rows(string $sql): array {
    $rows = $this->wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  /** @return array<string, mixed> */
  protected function outboxRow(string $eventId): array {
    return $this->rows($this->wpdb->prepare("SELECT * FROM `{$this->table('integration_outbox')}` WHERE event_id = %s", $eventId))[0] ?? [];
  }

  /** @return array<string, mixed> */
  protected function processRow(int $id): array {
    return $this->rows("SELECT * FROM `{$this->table('long_processes')}` WHERE id = $id")[0] ?? [];
  }

  /**
   * Pending Action Scheduler actions of the consumer on $hook (suffix after the prefix).
   *
   * @return list<array{id: int, args: array<mixed>, due: ?int}>
   */
  protected function pending(string $hook): array {
    $ids = as_get_scheduled_actions(['hook' => $this->config->hook($hook), 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1], 'ids');
    return array_map(static function ($id): array {
      $action = \ActionScheduler::store()->fetch_action((string) $id);
      return ['id' => (int) $id, 'args' => $action->get_args(), 'due' => $action->get_schedule()->get_date()?->getTimestamp()];
    }, array_values((array) $ids));
  }

  /** @return list<array{0: int, 1: string}> due pending actions of the consumer, oldest first */
  protected function dueActions(): array {
    $rows = $this->wpdb->get_results($this->wpdb->prepare(
      "SELECT action_id, hook FROM `{$this->wpdb->prefix}actionscheduler_actions`
        WHERE status = 'pending' AND hook LIKE %s AND scheduled_date_gmt <= %s ORDER BY scheduled_date_gmt ASC, action_id ASC",
      $this->wpdb->esc_like(RbConsumer::PREFIX . '_') . '%',
      gmdate('Y-m-d H:i:s')
    ));
    return array_map(static fn ($r) => [(int) $r->action_id, (string) $r->hook], is_array($rows) ? $rows : []);
  }

  /**
   * "$seconds later" for both runtimes: every pending Action Scheduler
   * action of the consumer and every time column of its outbox, process
   * and intent rows move $seconds into the past.
   */
  protected function age(int $seconds): void {
    $db = $this->wpdb;
    $db->query($db->prepare(
      "UPDATE `{$db->prefix}actionscheduler_actions`
          SET scheduled_date_gmt = DATE_SUB(scheduled_date_gmt, INTERVAL %d SECOND), scheduled_date_local = DATE_SUB(scheduled_date_local, INTERVAL %d SECOND)
        WHERE status = 'pending' AND hook LIKE %s",
      $seconds, $seconds, $db->esc_like(RbConsumer::PREFIX . '_') . '%'
    ));
    // Action Scheduler reads the due time from the serialized schedule too.
    foreach ($this->rows($db->prepare("SELECT action_id, schedule FROM `{$db->prefix}actionscheduler_actions` WHERE status = 'pending' AND hook LIKE %s", $db->esc_like(RbConsumer::PREFIX . '_') . '%')) as $row) {
      $schedule = @unserialize((string) $row['schedule']);
      if ($schedule instanceof \ActionScheduler_SimpleSchedule) {
        $date = $schedule->get_date();
        if ($date !== null) {
          $moved = new \ActionScheduler_SimpleSchedule(new \ActionScheduler_DateTime('@' . ($date->getTimestamp() - $seconds)));
          $db->update("{$db->prefix}actionscheduler_actions", ['schedule' => serialize($moved)], ['action_id' => (int) $row['action_id']]);
        }
      }
    }
    foreach (['integration_outbox' => ['scheduled_at', 'next_attempt_at', 'locked_until', 'created_at', 'processed_at'], 'long_processes' => ['created_at', 'updated_at'], 'ddd_wakeups' => ['due_at', 'created_at', 'updated_at']] as $table => $columns) {
      if ((string) $db->get_var($db->prepare('SHOW TABLES LIKE %s', $this->table($table))) !== $this->table($table)) {
        continue;
      }
      $sets = implode(', ', array_map(static fn (string $c) => "`$c` = IF(`$c` IS NULL, NULL, DATE_SUB(`$c`, INTERVAL $seconds SECOND))", $columns));
      $db->query("UPDATE `{$this->table($table)}` SET $sets");
    }
  }

  protected function admin(): WpdbOutboxAdministration {
    return new WpdbOutboxAdministration($this->config->prefix(), new SystemClock());
  }

  // ── internals ────────────────────────────────────────────────────────────

  private function idBase(): void {
    $base = random_int(1_000_000, 900_000_000);
    $this->wpdb->query(sprintf('ALTER TABLE `%s` AUTO_INCREMENT = %d', $this->table('long_processes'), $base));
  }

  /** The legacy install path sets the process id base too (called by the cases after a legacy migrate). */
  protected function legacyIdBase(): void {
    $this->idBase();
  }

  private function resetStatics(): void {
    RuntimeReset::forget_for_tests();
    Correlation::reset();
    Reactions::reset();
    WpdbTransactionDepth::reset_for_tests();
    WpLedgeredDelivery::reset_for_tests();
    IntegrationHookName::reset();
    HostDefaults::reset_for_tests();
    HostDefaultsWiring::register();
    $this->runner = null;
  }

  private function wipe(): void {
    global $wp_filter;
    $prefix = RbConsumer::PREFIX . '_';
    foreach (array_keys((array) $wp_filter) as $hook) {
      if (str_starts_with((string) $hook, $prefix)) {
        remove_all_actions((string) $hook);
      }
    }
    $db = $this->wpdb;
    foreach ((array) $db->get_col($db->prepare(
      'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
      $db->esc_like($db->prefix . $prefix) . '%'
    )) as $table) {
      $db->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $db->query($db->prepare("DELETE FROM `{$db->options}` WHERE option_name LIKE %s", $db->esc_like($prefix) . '%'));
    wp_cache_flush();
    $like = $db->esc_like($prefix) . '%';
    $db->query($db->prepare(
      "DELETE l FROM `{$db->prefix}actionscheduler_logs` l JOIN `{$db->prefix}actionscheduler_actions` a ON a.action_id = l.action_id WHERE a.hook LIKE %s",
      $like
    ));
    $db->query($db->prepare("DELETE FROM `{$db->prefix}actionscheduler_actions` WHERE hook LIKE %s", $like));
    $db->query($db->prepare("DELETE FROM `{$db->prefix}actionscheduler_groups` WHERE slug LIKE %s", $db->esc_like(RbConsumer::PREFIX . '-') . '%'));
  }
}
