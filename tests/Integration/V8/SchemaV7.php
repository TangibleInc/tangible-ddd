<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Infra\IDDDConfig;

use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\install_behaviour_workflow_item_tables;
use function TangibleDDD\WordPress\install_behaviour_workflow_meta_table;
use function TangibleDDD\WordPress\install_behaviour_workflow_tables;
use function TangibleDDD\WordPress\install_command_audit_table;
use function TangibleDDD\WordPress\install_touches_table;

/**
 * The schema a 0.6.5/0.6.6 winner leaves (DDD_SCHEMA_VERSION 7): the
 * outbox, DLQ and long_processes DDL verbatim from v0.6.6
 * `ddd-wordpress/tables.php`, the other tables unchanged since v7, and the
 * installed-version option at 7. No v8 table exists.
 */
final class SchemaV7 {

  public static function install(IDDDConfig $config): void {
    global $wpdb;
    if (!function_exists('dbDelta')) {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$config->table('integration_outbox')} (
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
  ) $charset");

    dbDelta("CREATE TABLE {$config->table('integration_dlq')} (
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
  ) $charset");

    dbDelta("CREATE TABLE {$config->table('long_processes')} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (id),
    process_class VARCHAR(255) NOT NULL,
    business_data JSON NOT NULL,
    steps JSON NULL,
    step_index INT UNSIGNED NOT NULL DEFAULT 0,
    step_name VARCHAR(128) NULL,
    status ENUM('pending', 'running', 'scheduled', 'suspended', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    waiting_for VARCHAR(255) NULL,
    match_criteria JSON NULL,
    await_mechanism JSON NULL,
    payload JSON NULL,
    correlation_id CHAR(36) NOT NULL,
    ignited_by_event_id VARCHAR(64) NULL,
    source VARCHAR(16) NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_ignition (ignited_by_event_id),
    KEY idx_status (status),
    KEY idx_waiting (waiting_for, status),
    KEY idx_correlation (correlation_id),
    KEY idx_class (process_class),
    KEY idx_blog_status (blog_id, status)
  ) $charset");

    install_command_audit_table($config);
    install_touches_table($config);
    install_behaviour_workflow_tables($config);
    install_behaviour_workflow_meta_table($config);
    install_behaviour_workflow_item_tables($config);

    update_option(ddd_schema_version_key($config), 7, false);
  }

  /** A long_processes row exactly as the 0.6 ProcessRepository::save() inserts it. */
  public static function process(IDDDConfig $config, string $class, string $status, int $step, ?string $ignitedBy, ?string $source = 'event'): int {
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert($config->table('long_processes'), [
      'process_class' => $class,
      'business_data' => '{"request_id":7}',
      'steps' => null,
      'step_index' => $step,
      'step_name' => null,
      'status' => $status,
      'correlation_id' => '11111111-1111-4111-8111-111111111111',
      'ignited_by_event_id' => $ignitedBy,
      'source' => $source,
      'created_at' => $now,
      'updated_at' => $now,
      'blog_id' => 1,
    ]);
    return (int) $wpdb->insert_id;
  }

  /** A pending outbox row as the 0.6 OutboxRepository::write() inserts it. */
  public static function outbox(IDDDConfig $config, string $eventId, string $status = 'pending'): void {
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert($config->table('integration_outbox'), [
      'event_id' => $eventId,
      'event_type' => 'v8.fact',
      'integration_action' => $config->integration_action('v8_fact'),
      'queue' => $config->prefix() . '-outbox',
      'correlation_id' => '22222222-2222-4222-8222-222222222222',
      'sequence' => 1,
      'payload' => '{"n":1}',
      'delay_seconds' => 0,
      'scheduled_at' => $now,
      'status' => $status,
      'attempts' => 0,
      'max_attempts' => 5,
      'created_at' => $now,
      'blog_id' => 1,
    ]);
  }
}
