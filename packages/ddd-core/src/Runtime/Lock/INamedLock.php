<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

/**
 * A session-scoped named lock (MySQL GET_LOCK, Postgres advisory lock on a
 * hashed name). Used where there is no process id to key an IProcessLock
 * on: the ignition gate of LegacyProcessStore (register 3.8, the hotfix
 * approach `ddd_ign_` + md5(prefix|class|event_id)). Wave 3 addition
 * (wave3-core CR-W3C-2).
 *
 * Error behaviour: acquire() returns only on a DEFINITE success and throws
 * LockNotAcquired otherwise (timeout, contention, NULL/false, query error;
 * bug 1). release() never throws; a failed release is logged as a bug.
 * Lifetime: acquire, act, release in `finally`. Need not be re-entrant.
 */
interface INamedLock {

  /** @throws LockNotAcquired */
  public function acquire(string $name, float $timeoutSeconds): void;

  public function release(string $name): void;
}
