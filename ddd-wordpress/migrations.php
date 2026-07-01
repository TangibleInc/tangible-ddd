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
 */
const DDD_SCHEMA_VERSION = 3;

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
  ];
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
 */
function register_migration_hooks(IDDDConfig $config): void {
  add_action('admin_init', static function () use ($config) {
    ddd_maybe_migrate($config);
  });
}
