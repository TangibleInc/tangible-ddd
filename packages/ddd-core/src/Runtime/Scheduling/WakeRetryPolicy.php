<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * The wake-execution retry budget (register 5.1): LockNotAcquired and
 * transient failures, 10 attempts, backoff 2 s × 2^n capped at 300 s.
 * Exhaustion goes to the operator view (layer `wakeup`); the intent keeps
 * being retried at the cap, so a wake is never dropped.
 */
final class WakeRetryPolicy {

  public const BUDGET = 10;
  public const BASE_SECONDS = 2;
  public const CAP_SECONDS = 300;

  /** Delay before retry number $attempt (1 = the first retry). */
  public static function backoff_seconds(int $attempt): int {
    $n = max(1, $attempt) - 1;
    return (int) min(self::CAP_SECONDS, self::BASE_SECONDS * (2 ** min($n, 20)));
  }
}
