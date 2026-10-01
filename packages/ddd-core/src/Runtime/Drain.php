<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use Psr\Log\LoggerInterface;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Infra\Services\ProcessingResult;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Process\IStrandedScanner;
use TangibleDDD\Runtime\Process\StrandedScanReport;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeHandler;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Support\Log;

/**
 * One bounded pass of durable work (register 3.6): what a cron line, a
 * shutdown function, `wp ddd relay --once` or a worker loop calls.
 *
 * runOnce() runs, in order and within ONE item budget and ONE wall-time
 * budget:
 *
 *   1. the relay step: OutboxProcessor::process_batch(remaining items);
 *   2. due deliveries: IDeliveryWorker::runDue(now, remaining) (pdo jobs);
 *   3. due wakeups: IWakeupScheduler::claimDue(now, remaining, lease), each
 *      run by the process IWakeHandler (Continue, Timeout, ResumeRetry) or
 *      the Deliver handler, then complete()d; a wake that throws (lock
 *      contention, a transient error) is retryLater()'d with the wake
 *      backoff (2 s × 2^n, capped at 300 s, register 5.1) and reported
 *      exhausted once its attempts reach the budget of 10. It is still
 *      kept: a wake is never dropped. A stale wake is a no-op and completes;
 *   4. the stranded scan (IStrandedScanner), unless the time budget ran out.
 *
 * Every stage is optional (null = skipped). It never loops or sleeps; a
 * host that wants a daemon writes `while (true) { $drain->runOnce(); sleep(1); }`.
 * Concurrent passes are safe: claims and leases keep them apart.
 *
 * After the relay batch, the delivery batch and each wake it calls
 * RuntimeReset::betweenMessages() in `finally`; a leak is cleaned, logged
 * and listed in the report. A stage that throws is logged and listed; the
 * pass continues with the next stage. runOnce() must be called outside any
 * open transaction and outside any Correlation scope.
 *
 * Budgets: $maxItems counts relay claims, deliveries and wakes; $maxSeconds
 * is wall time (not IClock, which tests freeze), checked before each stage
 * and each wake. Due times come from IClock.
 */
final class Drain {

  public function __construct(
    private readonly ?OutboxProcessor $relay = null,
    private readonly ?IWakeupScheduler $wakeups = null,
    private readonly ?IWakeHandler $processWakes = null,
    private readonly ?IDeliveryWorker $delivery = null,
    private readonly ?IStrandedScanner $stranded = null,
    private readonly ?IClock $clock = null,
    private readonly ?LoggerInterface $logger = null,
    private readonly ?IWakeHandler $deliverWakes = null,
    private readonly int $wakeLeaseSeconds = 60,
  ) {}

  public function runOnce(int $maxItems = 200, int $maxSeconds = 50): DrainReport {
    $started = hrtime(true);
    $outOfTime = static fn (): bool => (hrtime(true) - $started) / 1e9 >= $maxSeconds;

    $items = 0;
    $stopped = DrainReport::STOPPED_IDLE;
    $relay = null;
    $delivered = 0;
    $wakes = ['completed' => [], 'retried' => [], 'exhausted' => [], 'lost' => []];
    $stranded = null;
    $leaks = [];
    $errors = [];

    $budgetLeft = static function () use (&$items, $maxItems): int {
      return max(0, $maxItems - $items);
    };
    $stop = function () use (&$stopped, $outOfTime, $budgetLeft): bool {
      if ($outOfTime()) {
        $stopped = DrainReport::STOPPED_MAX_SECONDS;
        return true;
      }
      if ($budgetLeft() === 0) {
        $stopped = DrainReport::STOPPED_MAX_ITEMS;
        return true;
      }
      return false;
    };

    // 1. relay step
    if ($this->relay !== null && !$stop()) {
      $relay = $this->stage('relay', $errors, $leaks, fn (): ProcessingResult => $this->relay->process_batch($budgetLeft()));
      $items += $relay?->total ?? 0;
    }

    // 2. due deliveries
    if ($this->delivery !== null && !$stop()) {
      $delivered = (int) $this->stage('delivery', $errors, $leaks, fn (): int => $this->delivery->runDue($this->now(), $budgetLeft()));
      $items += $delivered;
    }

    // 3. due wakeups
    if ($this->wakeups !== null && !$stop()) {
      $claimed = $this->stage('wakeups', $errors, $leaks, fn (): array => $this->wakeups->claimDue($this->now(), $budgetLeft(), $this->wakeLeaseSeconds), false) ?? [];
      foreach ($claimed as $i => $claim) {
        if ($outOfTime()) {
          // Unrun claims stay leased until their lease expires, then recur.
          $stopped = DrainReport::STOPPED_MAX_SECONDS;
          break;
        }
        $items++;
        $this->runWake($claim, $wakes, $leaks);
      }
      if ($stopped === DrainReport::STOPPED_IDLE && $budgetLeft() === 0) {
        $stopped = DrainReport::STOPPED_MAX_ITEMS;
      }
    }

    // 4. stranded scan
    if ($this->stranded !== null && !$outOfTime()) {
      $stranded = $this->stage('stranded scan', $errors, $leaks, fn (): StrandedScanReport => $this->stranded->scanStranded($this->now()), false);
    } elseif ($this->stranded !== null) {
      $stopped = DrainReport::STOPPED_MAX_SECONDS;
    }

    if ($stopped === DrainReport::STOPPED_IDLE && $budgetLeft() === 0) {
      $stopped = DrainReport::STOPPED_MAX_ITEMS; // the budget ran out in the last stage that ran
    }

    return new DrainReport(
      $relay, $delivered,
      $wakes['completed'], $wakes['retried'], $wakes['exhausted'], $wakes['lost'],
      $stranded, $items, $stopped, $leaks, $errors,
    );
  }

  /**
   * @param array{completed: list<string>, retried: list<string>, exhausted: list<string>, lost: list<string>} $wakes
   * @param list<string> $leaks
   */
  private function runWake(ClaimedWakeup $claim, array &$wakes, array &$leaks): void {
    $key = $claim->intent->idempotencyKey;
    try {
      $handler = $claim->intent->kind === WakeKind::Deliver ? $this->deliverWakes : $this->processWakes;
      if ($handler === null) {
        throw new \LogicException("No wake handler for {$claim->intent->kind->value} wakes");
      }
      $handler->wake($claim->intent);

      if ($this->wakeups->complete($claim)) {
        $wakes['completed'][] = $key;
      } else {
        $wakes['lost'][] = $key;
        Log::write($this->logger, "[ddd drain] lease lost on complete of wake $key; another worker owns it");
      }
    } catch (\Throwable $e) {
      $attempt = $claim->attempts + 1;
      $next = $this->now()->modify('+' . WakeRetryPolicy::backoffSeconds($attempt) . ' seconds');
      if (!$this->wakeups->retryLater($claim, $e->getMessage(), $next)) {
        $wakes['lost'][] = $key;
        Log::write($this->logger, "[ddd drain] lease lost on retry of wake $key: {$e->getMessage()}");
      } elseif ($attempt >= WakeRetryPolicy::BUDGET) {
        $wakes['retried'][] = $key;
        $wakes['exhausted'][] = $key;
        Log::write($this->logger, sprintf(
          '[ddd drain] wake %s failed %d times (budget %d); kept, retried every %d s: %s',
          $key, $attempt, WakeRetryPolicy::BUDGET, WakeRetryPolicy::CAP_SECONDS, $e->getMessage()
        ), 'error');
      } else {
        $wakes['retried'][] = $key;
        Log::write($this->logger, sprintf('[ddd drain] wake %s attempt %d failed, retried at %s: %s', $key, $attempt, $next->format(DATE_ATOM), $e->getMessage()));
      }
    } finally {
      $this->reset($leaks);
    }
  }

  /**
   * Run one stage; a throw is logged and listed, the pass continues.
   *
   * @template T
   * @param callable():T $work
   * @param list<string> $errors
   * @param list<string> $leaks
   * @return T|null
   */
  private function stage(string $name, array &$errors, array &$leaks, callable $work, bool $resetAfter = true): mixed {
    try {
      return $work();
    } catch (\Throwable $e) {
      $errors[] = "$name: " . $e->getMessage();
      Log::write($this->logger, "[ddd drain] $name failed: " . $e->getMessage(), 'error');
      return null;
    } finally {
      if ($resetAfter) {
        $this->reset($leaks);
      }
    }
  }

  /** @param list<string> $leaks */
  private function reset(array &$leaks): void {
    try {
      RuntimeReset::betweenMessages();
    } catch (RuntimeLeakDetected $e) {
      $leaks[] = $e->getMessage();
      Log::write($this->logger, '[ddd drain] ' . $e->getMessage(), 'error');
    }
  }

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
  }
}
