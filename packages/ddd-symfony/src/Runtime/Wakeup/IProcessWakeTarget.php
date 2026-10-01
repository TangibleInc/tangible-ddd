<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * What a due wakeup intent wakes (sf-local seam between the `ddd_wakeups`
 * handler and the process runner). wake() is stale-safe on the runner's
 * side: under the process lock it re-reads the row and no-ops unless the
 * intent's expected status and step index still match (register 3.6).
 *
 * Errors: lock contention (LockNotAcquired / ProcessLockUnavailable), a lost
 * version fence (ConcurrentProcessModification) and transient DBAL errors
 * are retried by the handler; anything else exhausts the intent for the
 * operator.
 */
interface IProcessWakeTarget {

  public function wake(WakeupIntent $intent): void;
}
