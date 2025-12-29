<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\Exceptions\LockingException;

/**
 * Acquire a per-key lock with TTL.
 *
 * - Prefers atomic wp_cache_add when external object cache is enabled
 * - Falls back to atomic add_option when not
 *
 * WARNING: Uses usleep() for retry delays. Best used in async contexts.
 *
 * @param string $prefix Plugin prefix for lock key namespacing
 * @param string $name Lock name
 * @param int $duration Lock TTL in seconds (1-60)
 * @param int $retries Max retry attempts (max 20)
 * @param int $retry_interval_ms Delay between retries in ms (min 125)
 *
 * @throws LockingException When lock cannot be acquired after all retries
 *
 * @example
 * ```php
 * use function TangibleDDD\WordPress\acquire_lock;
 * use function TangibleDDD\WordPress\release_lock;
 *
 * try {
 *   acquire_lock('my_plugin', 'user_update_' . $user_id, duration: 10);
 *   // Critical section
 * } finally {
 *   release_lock('my_plugin', 'user_update_' . $user_id);
 * }
 * ```
 */
function acquire_lock(
  string $prefix,
  string $name,
  int $duration = 30,
  int $retries = 10,
  int $retry_interval_ms = 1000
): void {
  $duration = max(1, min($duration, 60));
  $retry_interval_ms = max(125, $retry_interval_ms);
  $retries = min($retries, 20);

  $key = "_{$prefix}_lock_{$name}";

  for ($i = 0; $i < $retries; $i++) {
    $now = time();
    $expires_at = $now + $duration;

    // Prefer atomic add in external object cache
    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_add')) {
      // Atomic: add only if not present
      if (wp_cache_add($key, $expires_at, 'locks', $duration)) {
        return; // Acquired
      }

      // Check for stale lock and try to clear for next iteration
      $locked_until = wp_cache_get($key, 'locks');
      $locked_until = is_numeric($locked_until) ? (int) $locked_until : 0;

      if ($locked_until > 0 && $now > $locked_until) {
        wp_cache_delete($key, 'locks'); // best-effort
      }
    } else {
      // Fallback: atomic DB-level add via add_option
      // Store the expiry timestamp as the value. 'no' autoload to keep it light.
      if (add_option($key, $expires_at, '', 'no')) {
        return; // Acquired
      }

      $locked_until = get_option($key);
      $locked_until = is_numeric($locked_until) ? (int) $locked_until : 0;

      if ($locked_until > 0 && $now > $locked_until) {
        // Try to clear stale lock, then retry
        delete_option($key);
      }
    }

    usleep($retry_interval_ms * 1000);
  }

  throw new LockingException("Could not acquire lock '{$name}' after {$retries} attempts");
}

/**
 * Release a lock acquired by acquire_lock.
 *
 * @param string $prefix Plugin prefix (must match acquire_lock)
 * @param string $name Lock name
 */
function release_lock(string $prefix, string $name): bool {
  $key = "_{$prefix}_lock_{$name}";

  if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_delete')) {
    return (bool) wp_cache_delete($key, 'locks');
  }

  return (bool) delete_option($key);
}

/**
 * Run a callback with a lock.
 *
 * Automatically acquires lock before and releases after callback execution.
 * Lock is released even if callback throws an exception.
 *
 * @template T
 * @param string $prefix Plugin prefix for lock key namespacing
 * @param string $name Lock name
 * @param callable():T $callback Code to run while holding lock
 * @param int $duration Lock TTL in seconds
 * @param int $retries Max retry attempts
 * @param int $retry_interval_ms Delay between retries in ms
 *
 * @return T Return value of the callback
 * @throws LockingException When lock cannot be acquired
 * @throws \Throwable Re-throws callback exceptions after releasing lock
 *
 * @example
 * ```php
 * use function TangibleDDD\WordPress\with_lock;
 *
 * $result = with_lock('my_plugin', 'counter_increment', function() {
 *   $count = get_option('counter', 0);
 *   update_option('counter', $count + 1);
 *   return $count + 1;
 * });
 * ```
 */
function with_lock(
  string $prefix,
  string $name,
  callable $callback,
  int $duration = 30,
  int $retries = 10,
  int $retry_interval_ms = 1000
): mixed {
  $lock_acquired = false;

  try {
    acquire_lock($prefix, $name, $duration, $retries, $retry_interval_ms);
    $lock_acquired = true;

    return $callback();
  } finally {
    if ($lock_acquired) {
      release_lock($prefix, $name);
    }
  }
}
