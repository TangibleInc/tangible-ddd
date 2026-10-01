<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Index one published fact's declarations (0.5.2, the bus calls this): the
 * fact carries its stamps, and its identity/story/raiser are in hand at the
 * moment of publication — no source→twin association needed, and the
 * command-less lanes (wp ddd announce, flat publishes) are covered because
 * every fact passes the bus. Never throws (decoration).
 */
function touches_index_fact(
  IDDDConfig $config,
  IIntegrationEvent $fact,
  string $event_id,
  string $correlation_id,
  ?string $command_id
): void {
  $touches = \TangibleDDD\Application\Events\Footprint::of_event($fact);
  if ($touches === []) {
    return;
  }

  touches_record($config, array_map(static fn (array $t) => [
    'aggregate' => $t['aggregate'],
    'aggregate_id' => $t['id'],
    'op' => $t['op'],
    'event_name' => $fact::name(),
    'event_id' => $event_id,
    'command_id' => $command_id,
    'correlation_id' => $correlation_id,
  ], $touches));
}

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
