<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Install all DDD framework tables.
 *
 * Call this on plugin activation.
 */
function install_tables(IDDDConfig $config): void {
  // dbDelta creates fresh tables AND heals additive schema changes. Loaded here
  // because install_tables may be invoked from activation / CLI / migration.
  if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  }

  install_outbox_tables($config);
  install_process_tables($config);
  install_command_audit_table($config);
  install_touches_table($config);
  install_behaviour_workflow_tables($config);
  install_behaviour_workflow_meta_table($config);
  install_behaviour_workflow_item_tables($config);
  install_wakeups_table($config);
  install_delivery_ledger_table($config);
}

/**
 * Install outbox and DLQ tables.
 */
function install_outbox_tables(IDDDConfig $config): void {
  global $wpdb;

  $outbox_table = $wpdb->prefix . $config->prefix() . '_integration_outbox';
  $dlq_table = $wpdb->prefix . $config->prefix() . '_integration_dlq';
  $charset = $wpdb->get_charset_collate();

  $outbox_sql = "CREATE TABLE $outbox_table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    integration_action VARCHAR(255) NOT NULL,
    message_kind ENUM('event','command') NOT NULL DEFAULT 'event',
    transport ENUM('action_scheduler','external') NOT NULL DEFAULT 'action_scheduler',
    queue VARCHAR(64) NULL,
    payload_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    correlation_id CHAR(36) NOT NULL,
    sequence INT UNSIGNED NOT NULL DEFAULT 0,
    command_id CHAR(32) NULL,
    payload JSON NOT NULL,
    delay_seconds INT UNSIGNED DEFAULT 0,
    scheduled_at DATETIME NOT NULL,
    is_unique TINYINT(1) DEFAULT 0,
    status ENUM('pending','processing','completed','failed','dlq','cancelled') DEFAULT 'pending',
    attempts INT UNSIGNED DEFAULT 0,
    max_attempts INT UNSIGNED DEFAULT 5,
    next_attempt_at DATETIME NULL,
    locked_until DATETIME NULL,
    locked_by VARCHAR(64) NULL,
    claim_token VARCHAR(64) NULL,
    last_error TEXT NULL,
    error_history JSON NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    blog_id BIGINT UNSIGNED DEFAULT 1,
    UNIQUE KEY uniq_event_id (event_id),
    KEY idx_status_scheduled (status, scheduled_at),
    KEY idx_correlation (correlation_id),
    KEY idx_next_attempt (status, next_attempt_at),
    KEY idx_blog_status (blog_id, status)
  ) $charset";

  $dlq_sql = "CREATE TABLE $dlq_table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    outbox_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    integration_action VARCHAR(255) NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    command_id CHAR(32) NULL,
    payload JSON NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    error_history JSON NULL,
    final_error TEXT NULL,
    moved_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED DEFAULT 1,
    KEY idx_event_type (event_type),
    KEY idx_correlation (correlation_id)
  ) $charset";

  dbDelta($outbox_sql);
  dbDelta($dlq_sql);
  install_relay_pauses_table($config);
}

/**
 * Install the relay pause rows table (schema v8, register 3.4 / C25): one
 * row per (holder, selector); `until_at` NULL = until released. The 0.6
 * `{prefix}_outbox_pauses` option is still read beside it until drained, so
 * a 0.6 copy's pause keeps holding and a rollback keeps the legacy holds.
 */
function install_relay_pauses_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('ddd_relay_pauses');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    holder VARCHAR(191) NOT NULL,
    selector VARCHAR(191) NOT NULL,
    until_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_hold (holder, selector)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install the durable wakeup intents table (schema v8, register 3.6, 5.3).
 *
 * One row per intent, unique on its idempotency key. The row is the
 * recovery ledger and the fencing source; the Action Scheduler action on
 * the legacy hook (as_action_id, hook, args) is its projection, made at
 * schedule time so a rolled-back 0.6 winner still fires it.
 *
 * status: pending (armed) | firing (its wake is running) | done | cancelled.
 */
function install_wakeups_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('ddd_wakeups');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    idempotency_key VARCHAR(191) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    process_id BIGINT UNSIGNED NULL,
    step_index INT UNSIGNED NULL,
    expected_status VARCHAR(16) NULL,
    due_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    claim_token VARCHAR(64) NULL,
    locked_until DATETIME NULL,
    hook VARCHAR(191) NULL,
    args LONGTEXT NULL,
    as_action_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_idempotency_key (idempotency_key),
    KEY idx_status_due (status, due_at),
    KEY idx_process (process_id, status)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install the per-subscriber delivery ledger (schema v8, register 3.5, 5.1;
 * CR-1): one row per (subscriber, event_id). `subscriber_key` is
 * sha1(subscriber_id), so long ids stay uniquely indexable.
 *
 * status: failed (attempts counted) | delivered | exhausted.
 */
function install_delivery_ledger_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('ddd_delivery_ledger');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    subscriber_key CHAR(40) NOT NULL,
    subscriber_id VARCHAR(512) NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'failed',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    redelivery LONGTEXT NULL,
    delivered_at DATETIME NULL,
    exhausted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_subscriber_event (subscriber_key, event_id),
    KEY idx_event (event_id),
    KEY idx_status (status)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install long processes table.
 *
 * Columns:
 * - business_data: JSON with child class constructor params
 * - steps: JSON with ProcessSteps state
 * - payload: JSON with polymorphic format {_class, _data}
 * - step_index + step_name: denormalized for debugging/querying
 * - version (v8): the fence every save checks (register 3.7, 3.8)
 * - ignition_key (v8): uuid5(event_id, process_class), set ONLY by the
 *   #[StartsOn] ignition path; UNIQUE (process_class, ignition_key) is the
 *   ignition gate (X7). NULL for manual starts (never deduped).
 * - quarantine_reason (v8): set with status `failed` for an undecodable row
 * - start_path (v8): `ignition` | `manual` on rows the v8 store inserts,
 *   NULL on rows a 0.6 copy wrote; the wp ignition check on
 *   ignited_by_event_id counts only the NULL ones, so a manual start inside
 *   a drain never blocks a later #[StartsOn] ignition of its class
 *   (change request WP8-1)
 */
function install_process_tables(IDDDConfig $config): void {
  global $wpdb;

  $table = $wpdb->prefix . $config->prefix() . '_long_processes';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    process_class VARCHAR(255) NOT NULL,
    business_data JSON NOT NULL,
    steps JSON NULL,
    step_index INT UNSIGNED NOT NULL DEFAULT 0,
    step_name VARCHAR(128) NULL,
    status ENUM('pending', 'running', 'scheduled', 'suspended', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    waiting_for VARCHAR(255) NULL,
    match_criteria JSON NULL,
    await_mechanism JSON NULL,
    payload JSON NULL,
    correlation_id CHAR(36) NOT NULL,
    ignited_by_event_id VARCHAR(64) NULL,
    ignition_key CHAR(36) NULL,
    source VARCHAR(16) NULL,
    start_path VARCHAR(16) NULL,
    last_error TEXT NULL,
    quarantine_reason TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_ignition (ignited_by_event_id),
    UNIQUE KEY uniq_ignition_key (process_class, ignition_key),
    KEY idx_status (status),
    KEY idx_waiting (waiting_for, status),
    KEY idx_correlation (correlation_id),
    KEY idx_class (process_class),
    KEY idx_blog_status (blog_id, status)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install command audit table.
 */
function install_command_audit_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('command_audit');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    command_id CHAR(32) NOT NULL,
    correlation_id CHAR(36) NULL,
    command_name VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL,
    source VARCHAR(16) NOT NULL,
    source_id VARCHAR(64) NOT NULL DEFAULT '',
    causation_id VARCHAR(64) NULL,
    causation_type VARCHAR(32) NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    peak_memory_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    parameters JSON NULL,
    events JSON NULL,
    error JSON NULL,
    environment JSON NULL,
    UNIQUE KEY uniq_command_id (command_id),
    KEY idx_correlation_id (correlation_id),
    KEY idx_started_at (started_at),
    KEY idx_command_name (command_name),
    KEY idx_status (status),
    KEY idx_blog_started (blog_id, started_at),
    KEY idx_source (source, source_id),
    KEY idx_causation (causation_id)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install the touches table (spec appendix 9, the touches lane) — the flat,
 * rebuildable query surface for declared state writes: one row per
 * declaration, versions minted under the unique key. The audit row's
 * enriched events JSON is the record; this is the index (never a write-side
 * authority). The biography query: WHERE aggregate = ? AND aggregate_id = ?.
 */
function install_touches_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('touches');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    aggregate VARCHAR(128) NOT NULL,
    aggregate_id VARCHAR(64) NOT NULL,
    op VARCHAR(16) NOT NULL,
    version INT UNSIGNED NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    event_id CHAR(36) NULL,
    command_id CHAR(32) NULL,
    correlation_id CHAR(36) NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    occurred_at DATETIME NOT NULL,
    UNIQUE KEY uniq_aggregate_version (aggregate, aggregate_id, version),
    KEY idx_aggregate (aggregate, aggregate_id),
    KEY idx_correlation_id (correlation_id),
    KEY idx_command_id (command_id)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install behaviour workflows table.
 *
 * This is a lightweight orchestration/workflow state machine persisted in MySQL.
 * It stores:
 * - a ref (ref_id + ref_type) to whatever the workflow is "about"
 * - a list of behaviour configs (JSON)
 * - execution results/history (JSON)
 * - progress cursor (current_idx + current_phase)
 * - optional meta bag for app-specific context (JSON)
 */
function install_behaviour_workflow_tables(IDDDConfig $config): void {
  global $wpdb;

  $table = $wpdb->prefix . $config->prefix() . '_behaviour_workflows';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    ref_id BIGINT UNSIGNED NOT NULL,
    ref_type VARCHAR(64) NOT NULL,
    root_workflow_id BIGINT UNSIGNED NULL,
    behaviour_configs JSON NOT NULL,
    behaviour_results JSON NOT NULL,
    current_idx INT UNSIGNED NOT NULL DEFAULT 0,
    current_phase INT UNSIGNED NOT NULL DEFAULT 1,
    is_complete TINYINT(1) NOT NULL DEFAULT 0,
    is_failed TINYINT(1) NOT NULL DEFAULT 0,
    correlation_id CHAR(36) NULL,
    meta JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_ref (ref_type, ref_id),
    KEY idx_root (root_workflow_id),
    KEY idx_status (is_complete, is_failed),
    KEY idx_correlation (correlation_id),
    KEY idx_blog_ref (blog_id, ref_type, ref_id),
    KEY idx_blog_status (blog_id, is_complete, is_failed)
  ) $charset";

  dbDelta($sql);
}

/**
 * Install the behaviour workflow meta side table (schema v7).
 *
 * Meta is stored WP-meta style — one row per key, values stringly-typed at
 * rest (non-scalars as JSON text) — instead of the workflow row's JSON `meta`
 * column, which is write-dead since v7. The shape deliberately matches cred's
 * long-standing {prefix}_behaviour_workflows_meta table so the legacy
 * repository there can eventually rebind with zero data movement.
 *
 * `id` is the workflow id (cred's historical column name, kept for that
 * compatibility; it is NOT this table's identity — meta_id is).
 */
function install_behaviour_workflow_meta_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('behaviour_workflows_meta');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (meta_id),
    id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meta_key VARCHAR(255) NULL,
    meta_value LONGTEXT NULL,
    KEY idx_workflow (id),
    KEY idx_meta_key (meta_key(191))
  ) $charset";

  dbDelta($sql);
}

/**
 * Install behaviour workflow items table.
 *
 * This is a "work item ledger" used by behaviour workflow runners to track per-item progress
 * for a given workflow step (behaviour_idx + phase).
 */
function install_behaviour_workflow_item_tables(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('behaviour_workflow_items');
  $charset = $wpdb->get_charset_collate();

  // Note: item_key is limited to 191 to stay safe with older utf8mb4 index limits.
  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    workflow_id BIGINT UNSIGNED NOT NULL,
    behaviour_idx INT UNSIGNED NOT NULL,
    phase INT UNSIGNED NOT NULL DEFAULT 1,
    item_key VARCHAR(191) NOT NULL,
    status ENUM('pending','waiting','failed','done','skipped','cancelled') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_item (workflow_id, behaviour_idx, phase, item_key),
    KEY idx_workflow_step_status (workflow_id, behaviour_idx, phase, status),
    KEY idx_blog_workflow (blog_id, workflow_id)
  ) $charset";

  dbDelta($sql);
}

/**
 * Probe whether a table is reachable by query.
 *
 * NOT `SHOW TABLES` — the WP test harness creates tables as TEMPORARY,
 * which SHOW TABLES never lists, so a SHOW-based probe reads every
 * consumer integration-test environment as "feature off" (process
 * discovery skipped, outbox lane closed). A SELECT under suppressed
 * errors sees temporary and permanent tables alike; existence = the
 * query didn't error (0 rows is still an int, error returns false).
 */
function table_reachable(string $table): bool {
  global $wpdb;

  $suppress = $wpdb->suppress_errors();
  $found = $wpdb->query("SELECT 1 FROM `{$table}` LIMIT 1");
  $wpdb->suppress_errors($suppress);

  return false !== $found;
}

/**
 * Check if outbox tables exist.
 */
function outbox_enabled(IDDDConfig $config): bool {
  global $wpdb;

  static $cache = [];
  $key = $config->prefix();

  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $cache[$key] = table_reachable($wpdb->prefix . $config->prefix() . '_integration_outbox');

  return $cache[$key];
}

/**
 * Check if process tables exist.
 */
function processes_enabled(IDDDConfig $config): bool {
  global $wpdb;

  static $cache = [];
  $key = $config->prefix();

  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $cache[$key] = table_reachable($wpdb->prefix . $config->prefix() . '_long_processes');

  return $cache[$key];
}
