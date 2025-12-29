<?php

namespace TangibleDDD\WordPress;

/**
 * Build a string of placeholders for a wpdb IN() clause.
 *
 * @example
 * $ids = [1,2,3];
 * $sql = "WHERE id IN (" . sql_placeholders($ids, '%d') . ")";
 * $wpdb->prepare($sql, ...$ids);
 */
function sql_placeholders(array $items, string $placeholder = '%d', string $placeholder_empty = ''): string {
  return count($items) ? implode(',', array_fill(0, count($items), $placeholder)) : $placeholder_empty;
}

/**
 * Check if a table has a column (uses information_schema).
 */
function table_has_column(string $table, string $column): bool {
  static $cache = [];
  $key = $table . '::' . $column;
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  global $wpdb;

  $found = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = %s
       AND COLUMN_NAME = %s",
    $table,
    $column
  ));

  return $cache[$key] = ((int) $found > 0);
}

/**
 * Add a column if it does not exist.
 *
 * $definition_sql should be the raw SQL fragment after column name, e.g.:
 * - "INT UNSIGNED NOT NULL DEFAULT 0"
 * - "VARCHAR(64) NULL"
 */
function table_add_column_if_missing(string $table, string $column, string $definition_sql): void {
  if (table_has_column($table, $column)) {
    return;
  }

  global $wpdb;
  $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition_sql");
}

/**
 * Add an index if it does not exist.
 *
 * $index_sql_fragment should include the KEY/INDEX definition, e.g.:
 * - "KEY idx_transport_status (transport, status, scheduled_at)"
 */
function table_add_index_if_missing(string $table, string $index_name, string $index_sql_fragment): void {
  global $wpdb;

  $exists = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = %s
       AND INDEX_NAME = %s",
    $table,
    $index_name
  ));

  if ($exists > 0) {
    return;
  }

  $wpdb->query("ALTER TABLE `$table` ADD $index_sql_fragment");
}


