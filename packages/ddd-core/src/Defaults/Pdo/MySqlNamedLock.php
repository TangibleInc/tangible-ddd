<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;

/**
 * IProcessLock on MySQL named locks (register 3.7, bug 1, CR-4).
 *
 * - Name: `ddd:` + sha1(consumer|tenant|process_id) (LockKey::mysqlName),
 *   within the 64-character GET_LOCK limit, so two consumers (or tenants)
 *   with the same process id never block each other (`lock.namespace`).
 * - Fail-closed: only GET_LOCK returning exactly 1 enters. 0 (timeout or
 *   contention), NULL (an error such as a killed thread) and a query error
 *   all throw LockNotAcquired; the critical section is never entered.
 * - Timeout: GET_LOCK takes whole seconds; the requested timeout is rounded
 *   up and never negative (a negative GET_LOCK timeout waits forever).
 * - The lock belongs to the SESSION of the host connection, independent of
 *   transactions: held across the wake's several step commits and released
 *   with RELEASE_LOCK in `finally`. A reconnect silently drops it, which is
 *   why every process save is version-fenced.
 * - release() never throws: a failed or unknown release is logged as a bug.
 * - forceReleaseAll() releases only the locks this instance took (never
 *   RELEASE_ALL_LOCKS(), which would also drop the host's own named locks).
 *
 * Not re-entrant by itself: wrap it in ReentrantProcessLock, so the backend
 * sees one acquisition per wake.
 */
final class MySqlNamedLock implements IProcessLock {

  private readonly LoggerInterface $logger;

  /** @var array<string, string> handle token → lock name */
  private array $held = [];

  private int $ticket = 0;

  public function __construct(private readonly IHostConnection $db, ?LoggerInterface $logger = null) {
    $this->logger = $logger ?? new NullLogger();
  }

  public static function nameOf(LockKey $key): string {
    return $key->mysqlName();
  }

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $name = self::nameOf($k);
    $timeout = max(0, (int) ceil($timeoutSeconds));

    try {
      $row = $this->db->fetchOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
    } catch (\Throwable $e) {
      throw new LockNotAcquired("GET_LOCK('$name') failed for {$k->id()}: " . $e->getMessage(), 0, $e);
    }

    $acquired = $row['acquired'] ?? null;
    if ($acquired === null) {
      throw new LockNotAcquired("GET_LOCK('$name') returned NULL for {$k->id()} (server error); not entering");
    }
    if ((string) $acquired !== '1') {
      throw new LockNotAcquired("GET_LOCK('$name') timed out after {$timeout}s for {$k->id()} (held by another session)");
    }

    $token = 'get_lock#' . (++$this->ticket) . '#' . $name;
    $this->held[$token] = $name;
    return new LockHandle($k, $token);
  }

  public function release(LockHandle $h): void {
    $name = $this->held[$h->token] ?? null;
    if ($name === null) {
      $this->logger->error(sprintf('[ddd lock] release of an unknown or already-released handle for %s (bug)', $h->key->id()));
      return;
    }
    unset($this->held[$h->token]);
    $this->releaseName($name, $h->key->id());
  }

  public function heldCount(): int {
    return count($this->held);
  }

  public function forceReleaseAll(): int {
    $held = $this->held;
    $this->held = [];
    foreach ($held as $name) {
      $this->releaseName($name, $name);
    }
    return count($held);
  }

  private function releaseName(string $name, string $what): void {
    try {
      $released = $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$name])['released'] ?? null;
      if ($released === null || (int) $released !== 1) {
        $this->logger->error(sprintf('[ddd lock] RELEASE_LOCK(%s) for %s returned %s: the lock was not held by this session (bug)', $name, $what, var_export($released, true)));
      }
    } catch (\Throwable $e) {
      $this->logger->error(sprintf('[ddd lock] RELEASE_LOCK(%s) for %s failed (bug): %s', $name, $what, $e->getMessage()));
    }
  }
}
