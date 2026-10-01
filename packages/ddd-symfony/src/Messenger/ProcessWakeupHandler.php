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
 *   error): retryLater() with 2 s x 2^n backoff capped at 300 s, until the
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

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IProcessWakeTarget $target,
    private readonly IWakeupScheduler $scheduler,
    private readonly IClock $clock,
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function __invoke(ProcessWakeupMessage $message): void {
    $claim = $message->toClaim();
    $key = $claim->intent->idempotencyKey;

    try {
      $this->target->wake($claim->intent);
    } catch (\Throwable $e) {
      $this->failed($claim, $e);
      return;
    }

    if (!$this->scheduler->complete($claim)) {
      $this->logger->info("[ddd wakeup] $key woke, but its lease was re-taken (a re-projected message owns it now)");
    }
  }

  public static function backoffSeconds(int $attempt): int {
    return (int) min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * (2 ** max(0, $attempt)));
  }

  private function failed(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $claim, \Throwable $e): void {
    $key = $claim->intent->idempotencyKey;
    $error = get_class($e) . ': ' . $e->getMessage();
    $attempt = $claim->attempts + 1;
    $retryable = self::isRetryable($e);

    if (!$retryable || $attempt >= self::BUDGET) {
      $why = $retryable ? "budget of " . self::BUDGET . " attempts spent" : 'not retryable';
      if ($this->scheduler instanceof DbalWakeupScheduler) {
        $this->scheduler->exhaust($claim, $error, $this->clock->now());
        $this->logger->error("[ddd wakeup] $key exhausted ($why); kept for the operator: $error", ['exception' => $e]);
        return;
      }
      $this->logger->error("[ddd wakeup] $key failed ($why), retrying at the cap: $error", ['exception' => $e]);
      $this->scheduler->retryLater($claim, $error, $this->clock->now()->modify('+' . self::MAX_DELAY_SECONDS . ' seconds'));
      return;
    }

    $delay = self::backoffSeconds($claim->attempts);
    $this->logger->notice("[ddd wakeup] $key attempt $attempt failed, retrying in {$delay}s: $error");
    $this->scheduler->retryLater($claim, $error, $this->clock->now()->modify("+{$delay} seconds"));
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
