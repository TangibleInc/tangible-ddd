<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * Transitional wave-2 IWakeupScheduler on WordPress (register 3.6, section
 * 8): every intent becomes an Action Scheduler action AT SCHEDULE TIME, on
 * the legacy hook with the legacy ASSOCIATIVE args (R4), in the consumer's
 * `processes` group. There is no intent table until schema v8 (wave 3), so
 * the AS action is the only record:
 *
 * - Timeout  → as_schedule_single_action(due_at, `{prefix}_await_timeout`,
 *              ['process_id' => int, 'step_index' => int])
 * - Continue → `{prefix}_process_continue` with ['process_id' => int]:
 *              as_enqueue_async_action when due now or earlier (0.6), else
 *              as_schedule_single_action at due_at.
 * - ResumeRetry, Deliver: LogicException (wave 3 kinds).
 *
 * Transitional gaps, all closed by the wave-3 intent table:
 * - schedule() does not require an open transaction (the AS insert runs on
 *   the same wpdb connection, so inside the runner's transaction it commits
 *   with the process save; outside one it is immediate, as in 0.6);
 * - no idempotency-key dedup (the runner schedules each intent once; an AS
 *   "has scheduled action" probe would also match the IN-PROGRESS action of
 *   the wake that is rescheduling itself);
 * - claim_due() returns [] and complete()/retry_later() return false: Action
 *   Scheduler claims and runs the actions itself.
 *
 * Error behaviour: an AS call returning 0 (no action created) throws
 * \RuntimeException, so the state change rolls back with it.
 */
final class ActionSchedulerWakeupScheduler implements IWakeupScheduler {

  public function __construct(private readonly IDDDConfig $config) {}

  public function schedule(WakeupIntent $i): void {
    [$hook, $args] = $this->projection($i);
    $group = $this->config->as_group('processes');

    if ($i->kind === WakeKind::Continue && $i->due_at->getTimestamp() <= time()) {
      $id = as_enqueue_async_action($hook, $args, $group);
    } else {
      $id = as_schedule_single_action($i->due_at->getTimestamp(), $hook, $args, $group);
    }

    if ((int) $id === 0) {
      throw new \RuntimeException("Action Scheduler did not create the $hook action for {$i->key}");
    }
  }

  public function cancel(string $idempotencyKey): void {
    if (!function_exists('as_unschedule_action')) {
      return;
    }
    $parts = explode(':', $idempotencyKey);
    if (count($parts) !== 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
      return; // not a key this scheduler projected
    }
    [$kind, $processId, $stepIndex] = [$parts[0], (int) $parts[1], (int) $parts[2]];

    $group = $this->config->as_group('processes');
    if ($kind === WakeKind::Timeout->value) {
      as_unschedule_action($this->config->hook('await_timeout'), ['process_id' => $processId, 'step_index' => $stepIndex], $group);
    } elseif ($kind === WakeKind::Continue->value) {
      as_unschedule_action($this->config->hook('process_continue'), ['process_id' => $processId], $group);
    }
  }

  public function claim_due(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    return [];
  }

  public function complete(ClaimedWakeup $w): bool {
    return false;
  }

  public function retry_later(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    return false;
  }

  /** @return array{0: string, 1: array<string, int>} */
  private function projection(WakeupIntent $i): array {
    if ($i->process_id === null) {
      throw new \InvalidArgumentException("Wakeup {$i->key} has no process id");
    }

    return match ($i->kind) {
      WakeKind::Timeout => [
        $this->config->hook('await_timeout'),
        ['process_id' => $i->process_id, 'step_index' => (int) $i->step_index],
      ],
      WakeKind::Continue => [
        $this->config->hook('process_continue'),
        ['process_id' => $i->process_id],
      ],
      default => throw new \LogicException("Wake kind {$i->kind->value} is not supported by the transitional Action Scheduler wakeups (wave 3)"),
    };
  }
}
