<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Support\Log;

/**
 * The intent bracket of a wake fired by Action Scheduler on wp (register
 * 3.6, 5.3): before the runner runs, the wake's pending intent rows become
 * `firing`; when it returns they are `done`; when it throws (LockNotAcquired
 * included) they go back to `pending` with the attempt, the error and the
 * wake-layer backoff (register 5.1: 10 attempts, 2 s × 2^n capped at
 * 300 s, then `exhausted`), the exception propagates (AS records the failed
 * action) and the next relay tick after the backoff re-projects the
 * intent: the wake is re-queued, never lost (`lock.contention` "later
 * succeeds"). A QuarantinedProcess closes the intents as `cancelled`.
 *
 * On a consumer without schema v8 (no intent table) it only runs the wake.
 */
final class WpWakeBracket {

  public static function run(IDDDConfig $config, WakeKind $kind, int $processId, ?int $stepIndex, callable $wake): void {
    $scheduler = HostDefaults::for(IWakeupScheduler::class, $config);
    if (!$scheduler instanceof WpdbWakeupScheduler) {
      $wake();
      return;
    }

    $scheduler->begin($kind, $processId, $stepIndex);
    try {
      $wake();
    } catch (\Throwable $e) {
      self::settle($config, static fn () => $scheduler->finish($kind, $processId, $stepIndex, $e->getMessage(), self::isTerminal($e)), false);
      throw $e;
    }
    self::settle($config, static fn () => $scheduler->finish($kind, $processId, $stepIndex, null));
  }

  /**
   * A failure no retry can cure: the process row was quarantined (its
   * class or payload no longer decodes). Its intents close as `cancelled`
   * with the reason; the operator view lists the quarantined process.
   */
  private static function isTerminal(\Throwable $e): bool {
    return $e instanceof \TangibleDDD\Runtime\Process\QuarantinedProcess;
  }

  /**
   * Record the outcome; a failed bookkeeping write is logged and rethrown
   * after a successful wake (the action fails, the firing row is retried
   * by the stale sweep), and only logged after a failed one (the wake's
   * own exception is what Action Scheduler records).
   */
  private static function settle(IDDDConfig $config, callable $record, bool $rethrow = true): void {
    try {
      $record();
    } catch (\Throwable $e) {
      Log::write(null, sprintf('[%s-process] wakeup bookkeeping failed: %s', $config->prefix(), $e->getMessage()), 'error');
      if ($rethrow) {
        throw $e;
      }
    }
  }

  /**
   * The `{prefix}_ddd_wakeup` hook (ResumeRetry intents). The runner's
   * `wake(WakeupIntent)` entry runs it when the runner has one (core wave 3);
   * otherwise a `scheduled` process is continued and anything else is
   * closed with a logged note (nothing to re-run it with).
   *
   * @param callable(): object $runner the consumer's ProcessRunner
   */
  public static function resumeRetry(IDDDConfig $config, string $key, callable $runner): void {
    $scheduler = HostDefaults::for(IWakeupScheduler::class, $config);
    if (!$scheduler instanceof WpdbWakeupScheduler) {
      return;
    }
    $intent = $scheduler->beginKey($key);
    if ($intent === null) {
      return; // stale: done, cancelled or re-armed elsewhere
    }

    try {
      $r = $runner();
      if (method_exists($r, 'wake')) {
        $r->wake($intent);
      } elseif ($intent->expectedStatus === 'scheduled' && $intent->processId !== null) {
        $r->continue_scheduled($intent->processId);
      } else {
        Log::write(null, sprintf('[%s-process] wakeup %s: this ProcessRunner has no wake() entry for a %s intent; closed without a re-run', $config->prefix(), $key, $intent->kind->value), 'warning');
      }
    } catch (\Throwable $e) {
      self::settle($config, static fn () => $scheduler->finishKey($key, $e->getMessage(), self::isTerminal($e)), false);
      throw $e;
    }
    self::settle($config, static fn () => $scheduler->finishKey($key, null));
  }
}
