<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;

/**
 * The wp IProcessLock (register 3.7, section 6 bug 1): MySQL GET_LOCK on
 * BOTH the namespaced name `LockKey::mysql_name()` ('ddd:' + sha1(consumer|
 * tenant|process_id)) and the LEGACY name `ddd_process_<id>`, in that order,
 * in one all-or-nothing statement (WpNamedLock::acquire_both), fail-closed:
 * only a definite '1' enters; timeout, NULL and query errors throw
 * LockNotAcquired and leave neither name held.
 *
 * The legacy name has no consumer, tenant or database component and is
 * exactly what every 0.6 copy on the site takes, so mixed 0.6/0.7 requests
 * keep excluding each other. Because GET_LOCK names are server-global, two
 * consumers (or subsites, or installs on one MySQL server) with the same
 * process id still serialize against each other while the legacy name is
 * taken: a known serialization cost, not a correctness issue
 * (`lock.namespace` is `-` on wp until the legacy name is retired).
 *
 * Tenant: on multisite an empty LockKey tenant is filled with the current
 * blog id for the namespaced name (each blog has its own process table).
 *
 * Not re-entrant on its own terms (the runner wraps it in
 * ReentrantProcessLock), although MySQL would allow it. held_count() and
 * release_all() track acquisitions made through THIS instance.
 */
final class GetLockProcessLock implements IProcessLock {

  /** @var array<string, array{0: string, 1: string, 2: int}> handle token => [name, legacy name, outstanding] */
  private array $held = [];

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $name = self::name($k);
    $legacy = self::legacy_name($k);
    WpNamedLock::acquire_both($name, $legacy, max(0, (int) ceil($timeoutSeconds)));

    $token = "$name|$legacy";
    $this->held[$token] = [$name, $legacy, ($this->held[$token][2] ?? 0) + 1];
    return new LockHandle($k, $token);
  }

  public function release(LockHandle $h): void {
    $entry = $this->held[$h->token] ?? null;
    if ($entry === null) {
      \TangibleDDD\Runtime\Support\Log::write(null, "[ddd lock] release of {$h->token}, which this instance does not hold (bug)", 'error');
      return;
    }
    [$name, $legacy, $count] = $entry;
    if ($count <= 1) {
      unset($this->held[$h->token]);
    } else {
      $this->held[$h->token][2] = $count - 1;
    }
    WpNamedLock::release_both($name, $legacy);
  }

  public function held_count(): int {
    return array_sum(array_column($this->held, 2));
  }

  public function release_all(): int {
    $dropped = $this->held_count();
    $held = $this->held;
    $this->held = [];
    foreach ($held as [$name, $legacy, $count]) {
      for ($i = 0; $i < $count; $i++) {
        WpNamedLock::release_both($name, $legacy);
      }
    }
    return $dropped;
  }

  /** The namespaced name, with the blog id as tenant on multisite when the key carries none. */
  public static function name(LockKey $k): string {
    if ($k->tenant === '' && function_exists('is_multisite') && is_multisite()) {
      $k = new LockKey($k->consumer, (string) get_current_blog_id(), $k->process_id);
    }
    return $k->mysql_name();
  }

  /** The 0.6 per-process lock name (R4: unchanged during the compatibility window). */
  public static function legacy_name(LockKey $k): string {
    return 'ddd_process_' . $k->process_id;
  }
}
