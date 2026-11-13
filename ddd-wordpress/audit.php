<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Check if command auditing is enabled (table exists).
 */
function command_audit_enabled(IDDDConfig $config): bool {
  static $enabled = [];
  $prefix = $config->prefix();

  if (isset($enabled[$prefix])) {
    return $enabled[$prefix];
  }

  global $wpdb;

  $table = $config->table('command_audit');
  $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  $enabled[$prefix] = ((string) $found === $table);

  return $enabled[$prefix];
}

/**
 * Whether command_audit has its intents column (schema v8). A consumer that
 * has not migrated yet must keep finalising its audit rows, so the intents
 * write is skipped until the column exists. Only a positive answer is
 * cached per prefix: a long-lived worker that outlives the migration must
 * start writing intents without a restart.
 */
function command_audit_has_intents_column(IDDDConfig $config): bool {
  static $present = [];
  $prefix = $config->prefix();

  if (isset($present[$prefix])) {
    return true;
  }

  if( ddd_column_exists($config->table('command_audit'), 'intents') ) {
    $present[$prefix] = true;
    return true;
  }

  return false;
}

/**
 * Write the initial audit record when command starts.
 */
function command_audit_preflight(IDDDConfig $config, array $data): void {
  global $wpdb;
  $table = $config->table('command_audit');

  $row = [
    'command_id' => (string) ($data['command_id'] ?? ''),
    'correlation_id' => isset($data['correlation_id']) ? (string) $data['correlation_id'] : null,
    'command_name' => (string) ($data['command_name'] ?? ''),
    'status' => 'in_progress',
    'source' => (string) ($data['source'] ?? 'system'),
    'source_id' => (string) ($data['source_id'] ?? ''),
    'causation_id' => isset($data['causation_id']) ? (string) $data['causation_id'] : null,
    'causation_type' => isset($data['causation_type']) ? (string) $data['causation_type'] : null,
    'blog_id' => (int) ($data['blog_id'] ?? (is_multisite() ? get_current_blog_id() : 1)),
    'started_at' => gmdate('Y-m-d H:i:s'),
    'parameters' => wp_json_encode($data['parameters'] ?? null, JSON_UNESCAPED_SLASHES),
    'environment' => wp_json_encode($data['environment'] ?? null, JSON_UNESCAPED_SLASHES),
  ];

  $wpdb->insert($table, $row);
}

/**
 * Complete the audit record when command finishes.
 */
function command_audit_finalise(IDDDConfig $config, array $data): void {
  global $wpdb;
  $table = $config->table('command_audit');

  $row = [
    'status' => (string) ($data['status'] ?? 'success'),
    'ended_at' => gmdate('Y-m-d H:i:s'),
    'duration_ms' => (int) ($data['duration_ms'] ?? 0),
    'peak_memory_bytes' => (int) ($data['peak_memory_bytes'] ?? 0),
    'events' => wp_json_encode($data['events'] ?? null, JSON_UNESCAPED_SLASHES),
    'error' => wp_json_encode($data['error'] ?? null, JSON_UNESCAPED_SLASHES),
  ];
  if( !empty($data['intents']) && command_audit_has_intents_column($config) ) {
    $row['intents'] = wp_json_encode($data['intents'], JSON_UNESCAPED_SLASHES);
  }

  $wpdb->update($table, $row, ['command_id' => (string) $data['command_id']]);
}
