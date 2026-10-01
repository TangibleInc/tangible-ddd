<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Schema migrations — hybrid auto-heal (Option D).
 *
 * Two mechanisms, version-gated so they run at most ONCE per increment
 * (no per-request dbDelta churn):
 *
 *   1. dbDelta(install_tables) — creates fresh tables and heals ADDITIVE
 *      changes (new columns / indexes) automatically from the canonical
 *      schema in tables.php.
 *   2. Explicit migrations — deterministic ALTERs for what dbDelta can't or
 *      shouldn't guess: renames, type narrowing, data backfills. Keyed by
 *      schema version, run in order. Additive adds here are guarded so they
 *      are idempotent and safe alongside dbDelta.
 *
 * Trigger: admin_init, per consumer config (see register_migration_hooks).
 * This is robust to non-WP-updater deploys (tar+scp) where
 * upgrader_process_complete never fires.
 *
 * Per-prefix: the schema SHAPE + DDD_SCHEMA_VERSION are framework-owned (one
 * constant); the INSTALLED version is stored per prefix, so each consumer
 * (cred / datastream / lms) heals independently on its next admin_init.
 */

/**
 * Current framework schema version. Bump when the canonical schema changes.
 *  - 1: original 6 tables
 *  - 2: command_audit gains causation_id + causation_type (+ idx_causation)
 *  - 3: behaviour_workflows gains correlation_id (+ idx_correlation)
 *  - 4: long_processes gains await_mechanism
 *  - 7: behaviour_workflows_meta side table; JSON meta column write-dead,
 *       existing values pivoted into rows
 *  - 8: durable contracts (register section 8 wave 3, additive only, R5):
 *       ddd_wakeups, ddd_delivery_ledger, ddd_relay_pauses; outbox
 *       claim_token; long_processes version, ignition_key (UNIQUE with
 *       process_class) and quarantine_reason; ignition keys and wakeup
 *       intents backfilled (ddd_migrate_v8)
 */
const DDD_SCHEMA_VERSION = 8;

/**
 * The schema version installed for this consumer (0 when never migrated).
 */
function ddd_schema_installed(IDDDConfig $config): int {
  return (int) get_option(ddd_schema_version_key($config), 0);
}

/**
 * Whether this consumer's tables are at least at $version. The v8 adapters
 * (fenced outbox claim, intent table, ledger, version-fenced process store)
 * are wired only for consumers whose migration has run; until then the
 * 0.6-schema paths stay in use.
 */
function ddd_schema_at_least(IDDDConfig $config, int $version): bool {
  return ddd_schema_installed($config) >= $version;
}

/**
 * Per-prefix option holding the installed schema version.
 */
function ddd_schema_version_key(IDDDConfig $config): string {
  return $config->prefix() . '_ddd_schema_version';
}

/**
 * PURE: the schema versions to apply, in order, for (installed, current].
 *
 * @return int[]
 */
function ddd_pending_migrations(int $installed, int $current): array {
  $pending = [];
  for ($v = $installed + 1; $v <= $current; $v++) {
    $pending[] = $v;
  }
  return $pending;
}

/**
 * Explicit, deterministic migrations keyed by schema version.
 *
 * Each is callable(IDDDConfig $config): void. Use the guarded helpers so the
 * migration is idempotent (safe if dbDelta already applied an additive change,
 * or if it runs twice).
 *
 * @return array<int, callable>
 */
function ddd_explicit_migrations(): array {
  return [
    // v2 — causation edge on command_audit. Additive, so dbDelta also covers
    // it; pinned here as the deterministic guarantee while dbDelta idempotency
    // is being verified. Safe to drop once dbDelta is confirmed on this schema.
    2 => static function (IDDDConfig $config): void {
      $table = $config->table('command_audit');
      ddd_add_column_if_missing($table, 'causation_id', 'VARCHAR(64) NULL', 'source_id');
      ddd_add_column_if_missing($table, 'causation_type', 'VARCHAR(32) NULL', 'causation_id');
      ddd_add_index_if_missing($table, 'idx_causation', '`causation_id`');
    },

    // v3 — correlation_id on behaviour_workflows. Additive; dbDelta covers
    // fresh installs; explicit entry guarantees the column for existing consumers.
    3 => static function (IDDDConfig $config): void {
      $table = $config->table('behaviour_workflows');
      ddd_add_column_if_missing($table, 'correlation_id', 'CHAR(36) NULL', 'is_failed');
      ddd_add_index_if_missing($table, 'idx_correlation', '`correlation_id`');
    },

    // v4 — await_mechanism on long_processes. Additive; dbDelta covers fresh
    // installs; explicit entry guarantees the column for consumers already at
    // v3, whose fast-path in ddd_maybe_migrate() would otherwise never
    // re-run dbDelta and leave ProcessRepository writing to a missing column.
    4 => static function (IDDDConfig $config): void {
      $table = $config->table('long_processes');
      ddd_add_column_if_missing($table, 'await_mechanism', 'JSON NULL', 'match_criteria');
    },

    // v6 — the touches table (0.5.0). A NEW table, so dbDelta covers both
    // fresh installs and existing consumers; the explicit entry guarantees
    // it for consumers already at v5 whose fast-path would otherwise skip
    // dbDelta. (v5 had no explicit entry — schema columns only.)
    6 => static function (IDDDConfig $config): void {
      if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      }
      install_touches_table($config);
    },

    // v7 — behaviour_workflows_meta side table + backfill. The JSON `meta`
    // column on behaviour_workflows goes write-dead: existing values are
    // pivoted into rows here, the repository writes rows from now on. The
    // column itself stays (never destructive in a migration); hydration
    // falls back to it only for rows with no meta rows at all.
    7 => static function (IDDDConfig $config): void {
      global $wpdb;

      if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      }
      install_behaviour_workflow_meta_table($config);

      $wf_table = $config->table('behaviour_workflows');
      $meta_table = $config->table('behaviour_workflows_meta');

      // Idempotent: only rows that have JSON meta and no side-table rows yet.
      $rows = $wpdb->get_results(
        "SELECT w.id, w.meta FROM `{$wf_table}` w
         WHERE w.meta IS NOT NULL AND w.meta <> '' AND w.meta <> 'null'
           AND NOT EXISTS (SELECT 1 FROM `{$meta_table}` m WHERE m.id = w.id)"
      );

      foreach ($rows ?: [] as $row) {
        $meta = json_decode((string) $row->meta, true);
        if (!is_array($meta)) {
          continue;
        }
        foreach ($meta as $key => $value) {
          $wpdb->insert($meta_table, [
            'id' => (int) $row->id,
            'meta_key' => (string) $key,
            'meta_value' => is_scalar($value) || $value === null
              ? (string) $value
              : wp_json_encode($value, JSON_UNESCAPED_SLASHES),
          ]);
        }
      }
    },

    // v8 — durable contracts (wave 3). Additive only (R5); see ddd_migrate_v8().
    8 => static function (IDDDConfig $config): void {
      ddd_migrate_v8($config);
    },
  ];
}

/**
 * Schema v8 (register section 8 wave 3, 3.4-3.8, 5.3; rulings on statuses
 * and rollback). Additive only: new tables, nullable or defaulted columns,
 * one UNIQUE key over a column that starts NULL. Nothing is renamed,
 * narrowed or deleted, so a 0.6 winner keeps running on the v8 tables after
 * a rollback (it tolerates installed > DDD_SCHEMA_VERSION, B18).
 *
 * 1. Tables ddd_wakeups, ddd_delivery_ledger, ddd_relay_pauses (dbDelta).
 * 2. Columns: outbox claim_token; long_processes version (default 1),
 *    ignition_key, quarantine_reason, start_path (change request WP8-1);
 *    UNIQUE (process_class, ignition_key).
 * 3. ignition_key backfill (ddd_backfill_ignition_keys): ignition-path rows
 *    only, in id order; the first row per (class, event) keeps the key,
 *    later ones are REPORTED and left NULL, never deleted.
 * 4. Wakeup intents backfilled from pending Action Scheduler actions on the
 *    legacy hooks (ddd_backfill_wakeup_intents), so a `scheduled` row left
 *    by 0.6 is not stranded and its queued action is the intent's projection.
 *
 * The report is stored in the `{prefix}_ddd_v8_migration_report` option and
 * duplicates are logged. Idempotent: safe to run again.
 *
 * @return array{ignition_backfilled: int, ignition_skipped: int, ignition_duplicates: list<array{process_class: string, event_id: string, kept: int, duplicate: int}>, wakeups_backfilled: int}
 */
function ddd_migrate_v8(IDDDConfig $config): array {
  if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  }

  install_wakeups_table($config);
  install_delivery_ledger_table($config);
  install_relay_pauses_table($config);

  $outbox = $config->table('integration_outbox');
  ddd_add_column_if_missing($outbox, 'claim_token', 'VARCHAR(64) NULL', 'locked_by');

  $processes = $config->table('long_processes');
  ddd_add_column_if_missing($processes, 'version', 'INT UNSIGNED NOT NULL DEFAULT 1', 'status');
  ddd_add_column_if_missing($processes, 'ignition_key', 'CHAR(36) NULL', 'ignited_by_event_id');
  ddd_add_column_if_missing($processes, 'quarantine_reason', 'TEXT NULL', 'last_error');
  ddd_add_column_if_missing($processes, 'start_path', 'VARCHAR(16) NULL', 'source');
  ddd_add_unique_index_if_missing($processes, 'uniq_ignition_key', '`process_class`, `ignition_key`');

  $ignition = ddd_backfill_ignition_keys($config);
  $report = [
    'ignition_backfilled' => $ignition['backfilled'],
    'ignition_skipped' => $ignition['skipped'],
    'ignition_duplicates' => $ignition['duplicates'],
    'wakeups_backfilled' => ddd_backfill_wakeup_intents($config),
  ];

  foreach ($report['ignition_duplicates'] as $dup) {
    error_log(sprintf(
      '[%s-ddd] schema v8: process #%d is a duplicate ignition of %s by event %s (kept #%d); left without ignition_key, not deleted',
      $config->prefix(), $dup['duplicate'], $dup['process_class'], $dup['event_id'], $dup['kept']
    ));
  }
  update_option($config->option('ddd_v8_migration_report'), $report, false);

  return $report;
}

/**
 * Backfill long_processes.ignition_key = uuid5(event_id, process_class) for
 * rows that came from the #[StartsOn] ignition path, in id order.
 *
 * "Ignition path": ignited_by_event_id is a UUID and the stored class still
 * exists and declares #[StartsOn]. Other rows (manual starts outside a
 * drain, unknown classes, non-UUID ids) are skipped. A manual start made
 * inside a drain of a #[StartsOn] class is indistinguishable from an
 * ignition in 0.6 data; if it shares (class, event) with an earlier row it
 * is reported as a duplicate, which is the conservative outcome (no row is
 * touched beyond keeping its key NULL).
 *
 * @return array{backfilled: int, skipped: int, duplicates: list<array{process_class: string, event_id: string, kept: int, duplicate: int}>}
 */
function ddd_backfill_ignition_keys(IDDDConfig $config): array {
  global $wpdb;

  $table = $config->table('long_processes');
  $backfilled = $skipped = 0;
  $duplicates = [];
  $starts_on = [];
  $last = 0;

  do {
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, process_class, ignited_by_event_id FROM `{$table}`
       WHERE id > %d AND ignited_by_event_id IS NOT NULL AND ignition_key IS NULL
       ORDER BY id ASC LIMIT 500",
      $last
    ));
    $rows = is_array($rows) ? $rows : [];

    foreach ($rows as $row) {
      $last = (int) $row->id;
      $class = (string) $row->process_class;
      $event_id = (string) $row->ignited_by_event_id;

      $starts_on[$class] ??= class_exists($class)
        && (new \ReflectionClass($class))->getAttributes(\TangibleDDD\Application\Process\StartsOn::class) !== [];
      if (!$starts_on[$class]) {
        $skipped++;
        continue;
      }

      try {
        $key = \TangibleDDD\Runtime\Process\IgnitionKey::for($event_id, $class);
      } catch (\InvalidArgumentException) {
        $skipped++;
        continue;
      }

      $kept = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `{$table}` WHERE process_class = %s AND ignition_key = %s LIMIT 1",
        $class,
        $key
      ));
      if ($kept !== null) {
        $duplicates[] = ['process_class' => $class, 'event_id' => $event_id, 'kept' => (int) $kept, 'duplicate' => $last];
        continue;
      }

      $suppress = $wpdb->suppress_errors(true);
      $updated = $wpdb->query($wpdb->prepare(
        "UPDATE `{$table}` SET ignition_key = %s WHERE id = %d AND ignition_key IS NULL",
        $key,
        $last
      ));
      $wpdb->suppress_errors($suppress);

      if ($updated === false) {
        // A new ignition took the key between the check and the update.
        $winner = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT id FROM `{$table}` WHERE process_class = %s AND ignition_key = %s LIMIT 1",
          $class,
          $key
        ));
        $duplicates[] = ['process_class' => $class, 'event_id' => $event_id, 'kept' => $winner, 'duplicate' => $last];
        continue;
      }
      $backfilled++;
    }
  } while (count($rows) === 500);

  return ['backfilled' => $backfilled, 'skipped' => $skipped, 'duplicates' => $duplicates];
}

/**
 * Backfill ddd_wakeups rows from PENDING Action Scheduler actions on the
 * legacy process hooks (register 5.3 step 5, 7.3 sequence 3):
 *
 * - `{prefix}_await_timeout` ['process_id' => int, 'step_index' => int]
 *   → `timeout:{pid}:{step}`, expected status `suspended`;
 * - `{prefix}_process_continue` ['process_id' => int] → `continue:{pid}:{step}`
 *   with the row's current step_index, expected status `scheduled`.
 *
 * The queued action stays exactly as it is and becomes the intent's
 * projection (as_action_id). Idempotent (INSERT IGNORE on the key).
 *
 * @return int intents inserted
 */
function ddd_backfill_wakeup_intents(IDDDConfig $config): int {
  global $wpdb;

  if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler_Store')) {
    return 0;
  }

  $wakeups = $config->table('ddd_wakeups');
  $processes = $config->table('long_processes');
  $inserted = 0;
  $now = gmdate('Y-m-d H:i:s');

  foreach (['await_timeout' => 'timeout', 'process_continue' => 'continue'] as $hook_name => $kind) {
    $hook = $config->hook($hook_name);
    $ids = as_get_scheduled_actions([
      'hook' => $hook,
      'status' => \ActionScheduler_Store::STATUS_PENDING,
      'per_page' => -1,
    ], 'ids');

    foreach ((array) $ids as $action_id) {
      $action = \ActionScheduler::store()->fetch_action((string) $action_id);
      $args = $action->get_args();
      $process_id = (int) ($args['process_id'] ?? ($args[0] ?? 0));
      if ($process_id <= 0) {
        continue;
      }

      if ($kind === 'timeout') {
        $step = (int) ($args['step_index'] ?? ($args[1] ?? 0));
        $expected = 'suspended';
        $proj_args = ['process_id' => $process_id, 'step_index' => $step];
      } else {
        $step = (int) $wpdb->get_var($wpdb->prepare("SELECT step_index FROM `{$processes}` WHERE id = %d", $process_id));
        $expected = 'scheduled';
        $proj_args = ['process_id' => $process_id];
      }

      $date = $action->get_schedule()?->get_date();
      $due = $date instanceof \DateTimeInterface
        ? (new \DateTimeImmutable('@' . $date->getTimestamp()))->format('Y-m-d H:i:s')
        : $now;

      $result = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO `{$wakeups}`
          (idempotency_key, kind, process_id, step_index, expected_status, due_at, status, attempts, hook, args, as_action_id, created_at, updated_at, blog_id)
         VALUES (%s, %s, %d, %d, %s, %s, 'pending', 0, %s, %s, %d, %s, %s, %d)",
        "$kind:$process_id:$step",
        $kind,
        $process_id,
        $step,
        $expected,
        $due,
        $hook,
        (string) wp_json_encode($proj_args),
        (int) $action_id,
        $now,
        $now,
        is_multisite() ? get_current_blog_id() : 1
      ));
      $inserted += (int) $result;
    }
  }

  return $inserted;
}

/**
 * Add a UNIQUE index only if no index of that name exists. Idempotent.
 */
function ddd_add_unique_index_if_missing(string $table, string $index, string $columns): void {
  global $wpdb;

  $exists = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
    $table,
    $index
  ));

  if ($exists > 0) {
    return;
  }

  $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$columns})");
}

/**
 * Add a column only if it is absent. Idempotent.
 */
function ddd_add_column_if_missing(string $table, string $column, string $definition, ?string $after = null): void {
  global $wpdb;

  $exists = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
    $table,
    $column
  ));

  if ($exists > 0) {
    return;
  }

  $after_sql = $after ? " AFTER `{$after}`" : '';
  // Identifiers are framework-owned constants, not user input.
  $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}{$after_sql}");
}

/**
 * Add an index only if it is absent. Idempotent.
 */
function ddd_add_index_if_missing(string $table, string $index, string $columns): void {
  global $wpdb;

  $exists = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
    $table,
    $index
  ));

  if ($exists > 0) {
    return;
  }

  $wpdb->query("ALTER TABLE `{$table}` ADD KEY `{$index}` ({$columns})");
}

/**
 * Run pending migrations for one consumer, if any. Version-gated: bails fast
 * when already current and tables exist, so it is cheap on every admin_init.
 */
function ddd_maybe_migrate(IDDDConfig $config): void {
  $key = ddd_schema_version_key($config);
  $installed = (int) get_option($key, 0);

  // Fast path: up to date and tables present.
  if ($installed >= DDD_SCHEMA_VERSION && outbox_enabled($config)) {
    return;
  }

  if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  }

  // 1. dbDelta: create fresh + heal additive changes from the canonical schema.
  install_tables($config);

  // 2. explicit migrations for the hard cases, in version order.
  $migrations = ddd_explicit_migrations();
  foreach (ddd_pending_migrations($installed, DDD_SCHEMA_VERSION) as $version) {
    if (isset($migrations[$version])) {
      $migrations[$version]($config);
    }
  }

  update_option($key, DDD_SCHEMA_VERSION, false);
}

/**
 * Register the per-consumer migration trigger. Called from register_hooks().
 *
 * Hooked on BOTH admin_init and init: admin_init alone never fires under
 * WP-CLI or on activation-less installs (boot() has no activation hook), so
 * fresh consumers would never get their tables. The up-to-date fast path in
 * ddd_maybe_migrate() is one get_option, so the init tick is cheap.
 */
function register_migration_hooks(IDDDConfig $config): void {
  $trigger = static function () use ($config) {
    ddd_maybe_migrate($config);
  };
  add_action('init', $trigger, 3);
  add_action('admin_init', $trigger);
}
