<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Runtime\Wakeup\IProcessWakeTarget;

/**
 * Handles one ProcessWakeupMessage from the `ddd_wakeups` transport
 * (register 3.6, 5.1 layer `wakeup`, 5.3).
 *
 * Wakes the target (the process runner), then completes the intent, fenced
 * on the message's claim token: a message whose lease expired and was
 * re-projected completes nothing (the newer message owns the intent).
 *
 * Failures never reach Messenger's retry (the intent row owns the budget):
 * - retryable (lock contention, a lost version fence, a transient DBAL
 *   error): retry_later() with 2 s x 2^n backoff capped at 300 s, until the
 *   10th failed attempt, which exhausts the intent;
 * - anything else: the intent is exhausted at once, kept with its error for
 *   the operator (ddd:ops:stranded), and an ERROR is logged.
 * Exhausting needs DbalWakeupScheduler; with another IWakeupScheduler the
 * intent is retried at the cap instead.
 */
final class ProcessWakeupHandler {

  public const BUDGET = 10;
  public const BASE_DELAY_SECONDS = 2;
  public const MAX_DELAY_SECONDS = 300;

  /** Handler results: woken (or stale) and completed. */
  public const COMPLETED = 'completed';
  /** The wake failed and the intent is due again after the backoff. */
  public const RETRIED = 'retried';
  /** The wake failed for the last time (budget, or not retryable); the intent is kept for the operator. */
  public const EXHAUSTED = 'exhausted';
  /** The complete / retry / exhaust write matched 0 rows: a re-projected message owns the intent. */
  public const LEASE_LOST = 'lease_lost';

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IProcessWakeTarget $target,
    private readonly IWakeupScheduler $scheduler,
    private readonly IClock $clock,
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * @return self::COMPLETED|self::RETRIED|self::EXHAUSTED|self::LEASE_LOST what happened to the
   *   intent (the HandledStamp result; a drain reports it like core DrainReport's wake lists)
   */
  public function __invoke(ProcessWakeupMessage $message): string {
    $claim = $message->to_claim();
    $key = $claim->intent->key;

    try {
      $this->target->wake($claim->intent);
    } catch (\Throwable $e) {
      return $this->failed($claim, $e);
    }

    if (!$this->scheduler->complete($claim)) {
      $this->logger->info("[ddd wakeup] $key woke, but its lease was re-taken (a re-projected message owns it now)");
      return self::LEASE_LOST;
    }
    return self::COMPLETED;
  }

  public static function backoff_seconds(int $attempt): int {
    return (int) min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * (2 ** max(0, $attempt)));
  }

  private function failed(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $claim, \Throwable $e): string {
    $key = $claim->intent->key;
    $error = get_class($e) . ': ' . $e->getMessage();
    $attempt = $claim->attempts + 1;
    $retryable = self::isRetryable($e);

    if (!$retryable || $attempt >= self::BUDGET) {
      $why = $retryable ? "budget of " . self::BUDGET . " attempts spent" : 'not retryable';
      if ($this->scheduler instanceof DbalWakeupScheduler) {
        $kept = $this->scheduler->exhaust($claim, $error, $this->clock->now());
        $this->logger->error("[ddd wakeup] $key exhausted ($why); kept for the operator: $error", ['exception' => $e]);
        return $kept ? self::EXHAUSTED : self::LEASE_LOST;
      }
      $this->logger->error("[ddd wakeup] $key failed ($why), retrying at the cap: $error", ['exception' => $e]);
      $kept = $this->scheduler->retry_later($claim, $error, $this->clock->now()->modify('+' . self::MAX_DELAY_SECONDS . ' seconds'));
      return $kept ? self::EXHAUSTED : self::LEASE_LOST;
    }

    $delay = self::backoff_seconds($claim->attempts);
    $this->logger->notice("[ddd wakeup] $key attempt $attempt failed, retrying in {$delay}s: $error");
    $kept = $this->scheduler->retry_later($claim, $error, $this->clock->now()->modify("+{$delay} seconds"));
    return $kept ? self::RETRIED : self::LEASE_LOST;
  }

  private static function isRetryable(\Throwable $e): bool {
    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
      if ($t instanceof LockNotAcquired || $t instanceof LockingException || $t instanceof ConcurrentProcessModification
        || $t instanceof RetryableException || $t instanceof ConnectionException) {
        return true;
      }
    }
    return false;
  }
}
