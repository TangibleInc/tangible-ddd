<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * The message-boundary reset (register 3.9): Correlation + Reactions + every
 * registered resetter (the host registers its EventsUnitOfWork and runner
 * transients), plus the held-lock guard.
 *
 * Called by `Drain::run_once()` in `finally` after each item, by sf's worker
 * reset, and by wp's relay tick.
 *
 * Error behaviour: it always cleans everything first, then throws
 * RuntimeLeakDetected if anything leaked (an open `Correlation::peek()`, a
 * guarded lock with held_count() > 0, or a resetter that threw). A leak is a
 * bracket bug and fails loudly; the next message still starts clean.
 * "Cleans" includes locks: a leaked guarded lock is recorded, then
 * IProcessLock::release_all() drops it (backend lock released, per-key
 * counts cleared), so the leak is reported once, the next reset is quiet,
 * and the next acquire() of that key reaches the backend again rather than
 * trusting a session lock a reconnect may have dropped. A resetter that
 * keeps throwing is reported on every reset (it is not "cleaned").
 *
 * Lifetime: registrations are boot-time and survive every reset. It never
 * touches HostDefaults or ConsumerRegistry.
 */
final class RuntimeReset {

  /** @var array<string, callable():void> */
  private static array $resetters = [];

  /** @var array<int, IProcessLock> */
  private static array $locks = [];

  /** Register a boot-time resetter under a unique name (re-registering replaces it). */
  public static function register(string $name, callable $reset): void {
    self::$resetters[$name] = $reset;
  }

  /** Assert this lock is held zero times at every message boundary. */
  public static function guard(IProcessLock $lock): void {
    self::$locks[spl_object_id($lock)] = $lock;
  }

  /** @throws RuntimeLeakDetected */
  public static function between_messages(): void {
    $leaks = [];

    if (Correlation::peek() !== null) {
      $leaks[] = 'Correlation scope still open (Correlation::peek() !== null)';
    }
    foreach (self::$locks as $lock) {
      $held = $lock->held_count();
      if ($held !== 0) {
        $leaks[] = sprintf('process lock %s still held %d time(s); force-released', get_class($lock), $held);
        try {
          $lock->release_all();
        } catch (\Throwable $e) {
          // Contract says never; an adapter that does must not stop the reset.
          $leaks[] = sprintf('force release of %s threw: %s', get_class($lock), $e->getMessage());
        }
      }
    }

    Correlation::reset();
    Reactions::reset();

    foreach (self::$resetters as $name => $reset) {
      try {
        $reset();
      } catch (\Throwable $e) {
        $leaks[] = sprintf("resetter '%s' failed: %s", $name, $e->getMessage());
      }
    }

    if ($leaks !== []) {
      throw new RuntimeLeakDetected('Runtime state leaked across a message boundary: ' . implode('; ', $leaks));
    }
  }

  /** Test seam only. */
  public static function forget_for_tests(): void {
    self::$resetters = [];
    self::$locks = [];
  }
}
