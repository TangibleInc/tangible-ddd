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
