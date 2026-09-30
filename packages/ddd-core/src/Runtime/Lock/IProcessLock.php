<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

/**
 * The per-process wake lock (register 3.7, section 5.2).
 *
 * Error behaviour:
 * - acquire() returns only on a DEFINITE success. MySQL GET_LOCK returning 0,
 *   NULL or a query error, and Postgres pg_try_advisory_lock returning false
 *   or erroring, all throw LockNotAcquired (bug 1). Nothing else is thrown.
 * - release() never throws; a failed release is logged as a bug.
 *
 * Lifetime: held for exactly one wake. Acquire, re-read the row, act, release
 * in `finally`. Never held across a message or runOnce item boundary; the
 * reset guard (RuntimeReset::guardLock) asserts heldCount() === 0 there.
 *
 * Connection rules: MySQL and Postgres session locks live on the connection
 * that took them. sf workers need a direct (non-pooled) connection; a
 * reconnect silently drops the lock, which is why every process save is
 * version-fenced (IProcessStore::save).
 *
 * Adapters need not be re-entrant; wrap them in ReentrantProcessLock.
 */
interface IProcessLock {

  /** @throws LockNotAcquired */
  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle;

  public function release(LockHandle $h): void;

  /** Outstanding acquisitions on this instance (for the worker-reset guard). */
  public function heldCount(): int;

  /**
   * Release every lock still held through THIS instance and forget the
   * handles; returns how many outstanding acquisitions were dropped (0 when
   * clean). Called by RuntimeReset::betweenMessages() after it has recorded
   * a lock leak, so a leak fails loudly once but is not sticky: the next
   * message boundary is clean and the next acquire() reaches the backend
   * again. Never throws (a failed backend release is logged as a bug, as in
   * release()). Locks held by other connections are untouched. Handles
   * issued before the call become stale; releasing one is ignored.
   */
  public function forceReleaseAll(): int;
}
