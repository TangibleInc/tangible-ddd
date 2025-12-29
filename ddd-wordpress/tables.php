<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Install all DDD framework tables.
 *
 * Call this on plugin activation.
 */
function install_tables(IDDDConfig $config): void {
  install_outbox_tables($config);
  install_process_tables($config);
  install_command_audit_table($config);
}

/**
 * Install outbox and DLQ tables.
 */
function install_outbox_tables(IDDDConfig $config): void {
  global $wpdb;

  $outbox_table = $wpdb->prefix . $config->prefix() . '_integration_outbox';
  $dlq_table = $wpdb->prefix . $config->prefix() . '_integration_dlq';
  $charset = $wpdb->get_charset_collate();

  $outbox_sql = "CREATE TABLE IF NOT EXISTS `$outbox_table` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  ) $charset";

  $dlq_sql = "CREATE TABLE IF NOT EXISTS `$dlq_table` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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

  $wpdb->query($outbox_sql);
  $wpdb->query($dlq_sql);
}

/**
 * Install long processes table.
 */
function install_process_tables(IDDDConfig $config): void {
  global $wpdb;

  $table = $wpdb->prefix . $config->prefix() . '_long_processes';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS `$table` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    process_class VARCHAR(255) NOT NULL,
    process_data LONGTEXT NOT NULL,
    current_step INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending', 'running', 'scheduled', 'suspended', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    waiting_for VARCHAR(255) NULL,
    match_criteria JSON NULL,
    payload LONGTEXT NULL,
    correlation_id CHAR(36) NOT NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_status (status),
    KEY idx_waiting (waiting_for, status),
    KEY idx_correlation (correlation_id),
    KEY idx_class (process_class),
    KEY idx_blog_status (blog_id, status)
  ) $charset";

  $wpdb->query($sql);
}

/**
 * Install command audit table.
 */
function install_command_audit_table(IDDDConfig $config): void {
  global $wpdb;

  $table = $config->table('command_audit');
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS `$table` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    command_id CHAR(32) NOT NULL,
    correlation_id CHAR(36) NULL,
    command_name VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL,
    source VARCHAR(16) NOT NULL,
    source_id VARCHAR(64) NOT NULL DEFAULT '',
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
    KEY idx_source (source, source_id)
  ) $charset";

  $wpdb->query($sql);
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

  $table = $wpdb->prefix . $config->prefix() . '_integration_outbox';
  $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  $cache[$key] = ((string) $found === $table);

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

  $table = $wpdb->prefix . $config->prefix() . '_long_processes';
  $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  $cache[$key] = ((string) $found === $table);

  return $cache[$key];
}
