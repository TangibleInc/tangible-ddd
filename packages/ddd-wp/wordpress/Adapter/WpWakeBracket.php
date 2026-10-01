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
 * included) they go back to `pending` with the attempt and the error, the
 * exception propagates (AS records the failed action) and the next relay
 * tick re-projects the intent: the wake is re-queued, never lost
 * (`lock.contention` "later succeeds").
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
      $scheduler->finish($kind, $processId, $stepIndex, $e->getMessage());
      throw $e;
    }
    $scheduler->finish($kind, $processId, $stepIndex, null);
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
      $scheduler->finishKey($key, $e->getMessage());
      throw $e;
    }
    $scheduler->finishKey($key, null);
  }
}
