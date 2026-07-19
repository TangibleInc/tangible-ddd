<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * The touches table writer (spec appendix 9, the touches lane): one row per
 * declaration, the flat query surface beside the audit row's enriched JSON
 * (the JSON is the record; this is the rebuildable index — never a
 * write-side authority, never consulted before a save).
 *
 * The version is a MATERIALIZED DERIVATION — position in the aggregate's
 * biography — minted under the UNIQUE (aggregate, aggregate_id, version)
 * key with a bounded retry: never a naked MAX()+1 (the mint must not have
 * the race it describes). An exhausted retry logs and drops the row — this
 * runs post-commit in the act bracket's finalise and NEVER throws.
 */
function touches_record(IDDDConfig $config, array $rows): void {
  global $wpdb;
  $table = $config->table('touches');
  $now = gmdate('Y-m-d H:i:s');
  $blog_id = is_multisite() ? get_current_blog_id() : 1;

  foreach ($rows as $row) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $version = 1 + (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(version) FROM `$table` WHERE aggregate = %s AND aggregate_id = %s",
        $row['aggregate'],
        $row['aggregate_id']
      ));

      $written = $wpdb->insert($table, [
        'aggregate' => $row['aggregate'],
        'aggregate_id' => $row['aggregate_id'],
        'op' => $row['op'],
        'version' => $version,
        'event_name' => $row['event_name'],
        'event_id' => $row['event_id'] ?? null,
        'command_id' => $row['command_id'] ?? null,
        'correlation_id' => $row['correlation_id'] ?? null,
        'blog_id' => $blog_id,
        'occurred_at' => $now,
      ]);

      if ($written) {
        continue 2;
      }
      // duplicate version under concurrency — re-read MAX and retry
    }

    error_log(sprintf(
      '[DDD Touches] dropped a touch row for %s#%s after 5 version-mint collisions.',
      $row['aggregate'],
      $row['aggregate_id']
    ));
  }
}
