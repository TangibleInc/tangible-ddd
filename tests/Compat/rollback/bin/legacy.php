<?php
/**
 * One php process with a LEGACY tangible-ddd (0.6.x) as the site's only
 * copy: the rollback winner of register 7.3. Boots WordPress against the
 * test database WITHOUT N (no N autoloader is ever included), then the
 * legacy copy's own vendor/autoload.php (its loader's late branch makes it
 * the winner and loads its procedural files) and its Action Scheduler,
 * composes the rollback consumer the way a 0.6 plugin does (0.6 classes,
 * 0.6 hooks), runs ONE operation and prints one JSON object.
 *
 *   DDD_ROLLBACK_LEGACY_DIR=<legacy copy> php bin/legacy.php <op> <base64 json args>
 *
 * The fixture classes (tests/Compat/rollback/Fixtures) are loaded from N's
 * test tree by a plain autoloader; they extend the 0.6 classes here.
 */

declare(strict_types=1);

$dir = getenv('DDD_ROLLBACK_LEGACY_DIR') ?: '';
if ($dir === '' || !is_file("$dir/vendor/autoload.php")) {
  fwrite(STDERR, "legacy.php: DDD_ROLLBACK_LEGACY_DIR does not point at an installed legacy copy ($dir)\n");
  exit(2);
}
define('DDD_ROLLBACK_RUNTIME', 'legacy');

// ── WordPress on the test database (the integration bootstrap's constants) ──
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'db_test');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'db');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'db');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'localhost');
$table_prefix = 'wptests_';
define('ABSPATH', getenv('WP_TESTS_ABSPATH') ?: '/var/www/html/');
ob_start();
require_once ABSPATH . 'wp-load.php';
ob_end_clean();
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// ── the legacy copy as the winner (its loader's late-load branch) ──────────
require $dir . '/vendor/autoload.php';
require_once $dir . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (!class_exists('Tangible_DDD_Versions', false) || !Tangible_DDD_Versions::instance()->is_initialized()) {
  fwrite(STDERR, "legacy.php: the legacy loader did not initialize\n");
  exit(2);
}
if (!class_exists('ActionScheduler', false) || !ActionScheduler::is_initialized()) {
  fwrite(STDERR, "legacy.php: Action Scheduler did not initialize\n");
  exit(2);
}

spl_autoload_register(static function (string $class): void {
  $ns = 'TangibleDDD\\Tests\\Compat\\Rollback\\Fixtures\\';
  if (str_starts_with($class, $ns)) {
    $file = dirname(__DIR__) . '/Fixtures/' . substr($class, strlen($ns)) . '.php';
    if (is_file($file)) {
      require_once $file;
    }
  }
});

use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbConsumer;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbJournal;

$op = $argv[1] ?? '';
$args = json_decode((string) base64_decode($argv[2] ?? '', true), true);
$args = is_array($args) ? $args : [];

$config = new DDDConfig(RbConsumer::PREFIX, RbConsumer::NAMESPACE_ROOT, 'legacy');
$outboxConfig = new OutboxConfig(action_scheduler_group: $config->as_group('outbox'));
$outbox = new OutboxRepository($config, $outboxConfig);
$repository = new ProcessRepository($config);
$runner = new ProcessRunner($config, $repository);
$processor = new OutboxProcessor($config, $outbox, $outboxConfig, new ActionSchedulerOutboxPublisher($outboxConfig));

// What a 0.6 consumer's boot registers for this consumer.
$container = new class($runner, $processor) {
  public function __construct(private readonly ProcessRunner $runner, private readonly OutboxProcessor $processor) {}

  public function get(string $id): object {
    return $id === OutboxProcessor::class ? $this->processor : $this->runner;
  }

  public function has(string $id): bool {
    return in_array($id, [ProcessRunner::class, OutboxProcessor::class], true);
  }
};
$wired = false;
$wire = static function () use (&$wired, $config, $runner, $container): void {
  if ($wired || !\TangibleDDD\WordPress\processes_enabled($config)) {
    return;
  }
  $wired = true;
  RbConsumer::wire($runner);
  \TangibleDDD\WordPress\register_process_hooks($config, static fn () => $container);
  \TangibleDDD\WordPress\register_outbox_hooks($config, static fn () => $container);
  RbConsumer::listen(static fn (string $class, callable $fn) => \TangibleDDD\WordPress\integration_action($class, $fn));
};
$wire();

$emit = static function (array $data): void {
  fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
};
/** @var \wpdb $wpdb */
global $wpdb;

try {
  switch ($op) {
    case 'version':
      $emit(['version' => Tangible_DDD_Versions::instance()->winner()['version'] ?? null, 'schema' => \TangibleDDD\WordPress\DDD_SCHEMA_VERSION]);
      break;

    case 'migrate':
      // admin_init / init of a 0.6 winner: install or heal, version-gated.
      \TangibleDDD\WordPress\ddd_maybe_migrate($config);
      $wire();
      $emit(['installed' => (int) get_option(\TangibleDDD\WordPress\ddd_schema_version_key($config), 0), 'legacy_schema' => \TangibleDDD\WordPress\DDD_SCHEMA_VERSION]);
      break;

    case 'publish':
      // What a 0.6 command's commit writes: OutboxRepository::write() per fact.
      $ids = [];
      foreach ((array) $args['facts'] as [$class, $params]) {
        $ids[] = $outbox->write(new $class(...$params), (string) ($args['correlation'] ?? \TangibleDDD\Domain\Shared\Uuid::v4()));
      }
      $emit(['ids' => $ids]);
      break;

    case 'lease':
      // A 0.6 relay fetch: leases (locked_until = now + 300 s) what it returns.
      $emit(['leased' => array_map(static fn ($e) => $e->event_id, $outbox->fetch_pending((int) ($args['limit'] ?? 50), 'legacy-worker'))]);
      break;

    case 'fail':
      // A 0.6 relay that keeps failing one row: mark_failed() n times, then the DLQ.
      for ($i = 0; $i < (int) ($args['times'] ?? 1); $i++) {
        $outbox->mark_failed((string) $args['event_id'], 'legacy transport down');
      }
      if (!empty($args['dlq'])) {
        $outbox->move_to_dlq((string) $args['event_id'], 'legacy gave up');
      }
      $emit(['ok' => true]);
      break;

    case 'pause':
      $outbox->set_pause((string) $args['holder'], (string) $args['selector'], (int) ($args['until'] ?? -1));
      $emit(['ok' => true]);
      break;

    case 'relay':
      // The recurring `{prefix}_outbox_process` action's body.
      $result = $processor->process_batch();
      $emit(['total' => $result->total, 'completed' => $result->completed, 'failed' => $result->failed, 'dlq' => $result->dlq]);
      break;

    case 'start':
      $class = (string) $args['class'];
      $runner->start(new $class(...(array) ($args['params'] ?? [])));
      $emit(['id' => (int) $wpdb->get_var("SELECT MAX(id) FROM `{$config->table('long_processes')}`")]);
      break;

    case 'save_duplicate':
      // A pre-existing duplicate ignition (two 0.6 workers raced the
      // has_ignition check): a second row of the class for the same fact.
      $class = (string) $args['class'];
      $p = new $class(...(array) ($args['params'] ?? []));
      $p->initialize_lifecycle(\TangibleDDD\Domain\Shared\Uuid::v4(), \TangibleDDD\Application\Process\ProcessSteps::from_reflection(
        array_values(array_filter((new ReflectionClass($p))->getMethods(ReflectionMethod::IS_PROTECTED), static fn ($m) => $m->getDeclaringClass()->getName() === $class)),
        []
      ));
      $p->mark_ignited_by((string) $args['event_id']);
      $p->mark_source('event');
      $emit(['id' => $repository->save($p)]);
      break;

    case 'deliver':
      // A fact arriving on its hook the way Action Scheduler delivers it.
      $class = (string) $args['class'];
      $fact = new $class(...(array) ($args['params'] ?? []));
      do_action($class::integration_action(), \TangibleDDD\Application\Events\IntegrationEnvelope::wrap(
        $fact->integration_payload(), (string) ($args['correlation'] ?? '44444444-4444-4444-8444-444444444444'), 1, (string) $args['event_id']
      ));
      $emit(['ok' => true]);
      break;

    case 'run_due':
      // One Action Scheduler queue pass of this consumer: every pending
      // action on its hooks that is due on the wall clock (async: always),
      // round after round, at most $rounds rounds.
      $ran = [];
      $failed = [];
      add_action('action_scheduler_failed_execution', static function ($id, $e) use (&$failed) {
        $failed[] = "$id: " . $e->getMessage();
      }, 10, 2);
      $seen = [];
      for ($round = 0; $round < (int) ($args['rounds'] ?? 5); $round++) {
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT action_id, hook FROM `{$wpdb->prefix}actionscheduler_actions`
            WHERE status = 'pending' AND hook LIKE %s AND scheduled_date_gmt <= %s ORDER BY scheduled_date_gmt ASC, action_id ASC",
          $wpdb->esc_like(RbConsumer::PREFIX . '_') . '%',
          gmdate('Y-m-d H:i:s')
        ));
        $fresh = array_values(array_filter((array) $rows, static fn ($r) => !isset($seen[(int) $r->action_id])));
        if ($fresh === []) {
          break;
        }
        foreach ($fresh as $r) {
          $seen[(int) $r->action_id] = true;
          ActionScheduler::runner()->process_action((int) $r->action_id, 'ddd-rollback-legacy');
          $ran[] = (string) $r->hook;
        }
      }
      $emit(['ran' => $ran, 'failed' => $failed]);
      break;

    case 'decode':
      // Every process row through the 0.6 repository (hydration = decode).
      $out = [];
      foreach ((array) $wpdb->get_col("SELECT id FROM `{$config->table('long_processes')}` ORDER BY id") as $id) {
        try {
          $p = $repository->find((int) $id);
          $out[(int) $id] = ['ok' => true, 'class' => $p === null ? null : get_class($p), 'status' => $p?->status(), 'step' => $p?->current_step_index()];
        } catch (Throwable $e) {
          $out[(int) $id] = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
      }
      $emit(['processes' => $out]);
      break;

    case 'stats':
      $emit(['stats' => $outbox->get_stats(), 'purged' => !empty($args['purge']) ? $outbox->purge_completed((int) $args['purge']) : null]);
      break;

    case 'audit':
      // A 0.6 command's audit pair (preflight + finalise).
      $cid = \TangibleDDD\Domain\Shared\Uuid::v4();
      \TangibleDDD\WordPress\command_audit_preflight($config, ['command_id' => $cid, 'command_name' => 'RbLegacyCommand', 'correlation_id' => \TangibleDDD\Domain\Shared\Uuid::v4(), 'parameters' => ['n' => 1]]);
      \TangibleDDD\WordPress\command_audit_finalise($config, ['command_id' => $cid, 'status' => 'success']);
      $emit(['command_id' => $cid]);
      break;

    case 'schedule_recurring':
      // What register_outbox_hooks() schedules on `init` when none exists.
      if (!as_next_scheduled_action($config->hook('outbox_process'))) {
        as_schedule_recurring_action(time(), $outboxConfig->processor_interval_seconds, $config->hook('outbox_process'), [], $config->as_group('outbox'));
      }
      $emit(['next' => as_next_scheduled_action($config->hook('outbox_process'))]);
      break;

    case 'workflow':
      // A behaviour workflow with a meta row and a work item, as the 0.6
      // repositories store them (column for column, on the 0.6 tables).
      $now = gmdate('Y-m-d H:i:s');
      $wf = $config->table('behaviour_workflows');
      $ok = $wpdb->insert($wf, ['ref_id' => 7, 'ref_type' => 'rb', 'behaviour_configs' => '[]', 'behaviour_results' => '[]', 'current_idx' => 0, 'current_phase' => 1, 'is_complete' => 0, 'is_failed' => 0, 'meta' => '{"rb":"legacy"}', 'created_at' => $now, 'updated_at' => $now]);
      if ($ok === false) {
        throw new RuntimeException("workflow insert failed: {$wpdb->last_error}");
      }
      $id = (int) $wpdb->insert_id;
      if (\TangibleDDD\WordPress\table_reachable($config->table('behaviour_workflows_meta'))) { // the meta side table is 0.6.3+
        $wpdb->insert($config->table('behaviour_workflows_meta'), ['id' => $id, 'meta_key' => 'rb', 'meta_value' => 'legacy']);
      }
      $ok = $wpdb->insert($config->table('behaviour_workflow_items'), ['workflow_id' => $id, 'behaviour_idx' => 0, 'phase' => 1, 'item_key' => 'k1', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
      if ($ok === false) {
        throw new RuntimeException("work item insert failed: {$wpdb->last_error}");
      }
      $emit(['id' => $id]);
      break;

    case 'journal':
      $emit(['journal' => RbJournal::all()]);
      break;

    default:
      fwrite(STDERR, "legacy.php: unknown op '$op'\n");
      exit(2);
  }
} catch (Throwable $e) {
  $emit(['error' => get_class($e) . ': ' . $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
  exit(1);
}
