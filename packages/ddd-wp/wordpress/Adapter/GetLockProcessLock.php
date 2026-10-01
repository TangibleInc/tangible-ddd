<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;

/**
 * Transitional wave-2 IProcessLock on WordPress (register 3.7, section 8):
 * MySQL GET_LOCK on the LEGACY name `ddd_process_<id>` only, fail-closed
 * (WpNamedLock). The legacy name has no consumer, tenant or database
 * component, so it is exactly the name every 0.6 copy on the site takes:
 * mixed 0.6/0.7 requests keep excluding each other. Wave 3 adds the
 * namespaced `LockKey::mysqlName()` before it.
 *
 * Not re-entrant on its own terms (the runner wraps it in
 * ReentrantProcessLock), although MySQL would allow it. heldCount() and
 * forceReleaseAll() track acquisitions made through THIS instance.
 */
final class GetLockProcessLock implements IProcessLock {

  /** @var array<string, int> lock name => outstanding acquisitions */
  private array $held = [];

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $name = self::legacyName($k);
    WpNamedLock::acquire($name, max(0, (int) ceil($timeoutSeconds)));
    $this->held[$name] = ($this->held[$name] ?? 0) + 1;
    return new LockHandle($k, $name);
  }

  public function release(LockHandle $h): void {
    $name = $h->token;
    if (!isset($this->held[$name])) {
      \TangibleDDD\Runtime\Support\Log::write(null, "[ddd lock] release of $name, which this instance does not hold (bug)", 'error');
      return;
    }
    if (--$this->held[$name] === 0) {
      unset($this->held[$name]);
    }
    WpNamedLock::release($name);
  }

  public function heldCount(): int {
    return array_sum($this->held);
  }

  public function forceReleaseAll(): int {
    $dropped = $this->heldCount();
    $held = $this->held;
    $this->held = [];
    foreach ($held as $name => $count) {
      for ($i = 0; $i < $count; $i++) {
        WpNamedLock::release($name);
      }
    }
    return $dropped;
  }

  /** The 0.6 per-process lock name (R4: unchanged during the compatibility window). */
  public static function legacyName(LockKey $k): string {
    return 'ddd_process_' . $k->processId;
  }
}
