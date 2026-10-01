<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

/**
 * Runs code on another MySQL connection by swapping the global `$wpdb`.
 *
 * Every wp adapter (stores, the GET_LOCK lock, Action Scheduler's store,
 * the transaction boundary) reads `$GLOBALS['wpdb']` at call time, so a
 * swapped global puts all of them on the other session: its own
 * transactions and its own named locks. That is how the fixture's worker 2
 * is "a second php-fpm child" inside one test process.
 *
 * The caller guarantees no transaction is open on the current connection
 * (ProcessHost::beforeNextProcessLockAcquire's contract): the transaction
 * depth counter (WpdbTransactionDepth) is process-wide.
 */
final class ConnectionSwitch {

  /**
   * @template T
   * @param callable(): T $fn
   * @return T
   */
  public static function on(\wpdb $db, callable $fn): mixed {
    $previous = $GLOBALS['wpdb'];
    if ($previous === $db) {
      return $fn();
    }
    $GLOBALS['wpdb'] = $db;
    try {
      return $fn();
    } finally {
      $GLOBALS['wpdb'] = $previous;
    }
  }

  /** A second session on the WordPress database, same table prefix, errors suppressed like the fixture's. */
  public static function open(\wpdb $like): \wpdb {
    $db = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $db->set_prefix($like->prefix);
    // Action Scheduler registers its tables as wpdb properties on the
    // global connection at init (ActionScheduler_StoreSchema); a worker
    // that drains on this session runs the AS store through it.
    foreach (['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs'] as $table) {
      if (isset($like->{$table})) {
        $db->{$table} = $like->{$table};
      }
    }
    $db->suppress_errors(true);
    if (!$db->check_connection(false)) {
      throw new \RuntimeException('conformance-wp: could not open a second MySQL connection');
    }
    return $db;
  }
}
