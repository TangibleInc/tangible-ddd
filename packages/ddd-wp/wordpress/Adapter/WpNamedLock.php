<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Support\Log;
use wpdb;

/**
 * MySQL named locks over the global `$wpdb`, fail-closed (bug 1, wave-1 fix):
 * only a definite '1' from GET_LOCK is an acquisition. '0' (timeout,
 * contended) and NULL (query error, killed connection) throw
 * LockNotAcquired, with the same reason text the 0.6.7 runner used, and no
 * RELEASE_LOCK is issued for a lock never held. GET_LOCK is re-entrant per
 * connection on MySQL 5.7+; each acquisition needs its own release.
 *
 * @internal shared by GetLockProcessLock and WpdbProcessStore (ignition lock)
 */
final class WpNamedLock {

  /** @throws LockNotAcquired */
  public static function acquire(string $name, int $timeoutSeconds = 5): void {
    $db = self::db();
    $acquired = $db->get_var($db->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeoutSeconds));

    if ($acquired === null || (string) $acquired !== '1') {
      $reason = $acquired === null
        ? 'GET_LOCK returned NULL' . (!empty($db->last_error) ? " ({$db->last_error})" : ' (lock query error)')
        : "GET_LOCK timed out after {$timeoutSeconds}s (lock held elsewhere)";
      throw new LockNotAcquired("Could not acquire lock $name: $reason. Nothing ran; the action fails and can be retried.");
    }
  }

  /**
   * Acquire $first, then $second, in ONE statement; all or nothing. When
   * $second is not acquired the statement releases $first itself, so a
   * lock never held by the caller is never left behind and no RELEASE is
   * issued from PHP. Each GET_LOCK is evaluated exactly once (each sits in
   * a derived table that LIMIT 1 materializes); no user variables.
   *
   * $second is bound FIRST in the statement text: the 0.6 per-process name
   * is what tooling, diagnostics and the wave-1 test doubles read off a
   * GET_LOCK query's first argument. The acquisition ORDER is still
   * $first then $second.
   *
   * Timeout: $second waits only for what is left of $timeoutSeconds after
   * $first was won (NOW(6) is the statement start, SYSDATE(6) the moment
   * the second GET_LOCK runs), so the whole call waits at most
   * $timeoutSeconds + 1 s (whole-second truncation), never twice the
   * timeout.
   *
   * @throws LockNotAcquired with the same reason texts as acquire()
   */
  public static function acquireBoth(string $first, string $second, int $timeoutSeconds = 5): void {
    $db = self::db();
    $acquired = $db->get_var($db->prepare(
      'SELECT IF(b.first = 1, IF(b.second = 1, 1, IF(RELEASE_LOCK(b.name) IS NULL, b.second, b.second)), b.first) AS acquired
       FROM (SELECT a.name, a.first, IF(a.first = 1, GET_LOCK(a.legacy, GREATEST(0, a.t - TIMESTAMPDIFF(SECOND, NOW(6), SYSDATE(6)))), NULL) AS second
             FROM (SELECT %s AS legacy, %s AS name, %d AS t, GET_LOCK(%s, %d) AS first LIMIT 1) a LIMIT 1) b',
      $second,
      $first,
      $timeoutSeconds,
      $first,
      $timeoutSeconds
    ));

    if ($acquired === null || (string) $acquired !== '1') {
      $name = "$first + $second";
      $reason = $acquired === null
        ? 'GET_LOCK returned NULL' . (!empty($db->last_error) ? " ({$db->last_error})" : ' (lock query error)')
        : "GET_LOCK timed out after {$timeoutSeconds}s (lock held elsewhere)";
      throw new LockNotAcquired("Could not acquire lock $name: $reason. Nothing ran; the action fails and can be retried.");
    }
  }

  /** Release both names (the reverse of acquireBoth()); never throws, a failure is logged as a bug. */
  public static function releaseBoth(string $first, string $second): void {
    try {
      $db = self::db();
      $db->get_var($db->prepare('SELECT COALESCE(RELEASE_LOCK(%s), 0) + COALESCE(RELEASE_LOCK(%s), 0)', $second, $first));
    } catch (\Throwable $e) {
      Log::write(null, "[ddd lock] RELEASE_LOCK($second, $first) failed (bug): " . $e->getMessage(), 'error');
    }
  }

  /**
   * Whether every name is free (IS_FREE_LOCK = 1; held by anyone, this
   * connection included, is not free). A query error answers false: a
   * probe that cannot see the lock must not report the holder as gone.
   */
  public static function isFree(string ...$names): bool {
    if ($names === []) {
      return true;
    }
    $db = self::db();
    $free = $db->get_var($db->prepare(
      'SELECT ' . implode(' AND ', array_fill(0, count($names), 'IS_FREE_LOCK(%s) = 1')),
      ...$names
    ));
    return $free !== null && (string) $free === '1';
  }

  /** Never throws; a failed release is logged as a bug. */
  public static function release(string $name): void {
    try {
      $db = self::db();
      $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $name));
    } catch (\Throwable $e) {
      Log::write(null, "[ddd lock] RELEASE_LOCK($name) failed (bug): " . $e->getMessage(), 'error');
    }
  }

  private static function db(): wpdb {
    $db = $GLOBALS['wpdb'] ?? null;
    if (!$db instanceof wpdb) {
      throw new LockNotAcquired('No WordPress database connection ($wpdb) is available for GET_LOCK.');
    }
    return $db;
  }
}
