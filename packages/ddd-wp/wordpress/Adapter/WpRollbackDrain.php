<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;

/**
 * `wp ddd drain --before-rollback` (register 3.6, 5.1; the rollback
 * runbook requires it): runs every pending Action Scheduler action on the
 * N-only hooks of one consumer NOW, round after round, until none is left
 * or $maxRounds is reached:
 *
 * - `{prefix}_ddd_redeliver` (handler retries): each either delivers or
 *   fails again and schedules its next redelivery, which the next round
 *   runs at once, so every pair ends delivered or exhausted (budget 5);
 * - `{prefix}_ddd_wakeup` (ResumeRetry intents);
 * - due by-reference integration actions (facts over Action Scheduler's
 *   args limit, WpLargeEnvelope): a 0.6 winner cannot resolve them. Future
 *   ones are not run early (the delay is the fact's); they are counted in
 *   `remaining`.
 *
 * Neither hook has a callback under 0.6, so whatever is still pending when
 * the winner switches back is failed by Action Scheduler and lost. Each
 * round first re-schedules redeliveries Action Scheduler lost
 * (WpLedgeredDelivery::restoreRedeliveries()) and re-projects every pending
 * wakeup intent that has no Action Scheduler action, ignoring its retry
 * backoff (a Timeout or Continue whose wake just failed is back in
 * `pending` with its action gone; re-projected, it sits future-dated on its
 * legacy hook, which 0.6 fires; a ResumeRetry lands on `{prefix}_ddd_wakeup`
 * and the round runs it). The report says how many remain: pending actions
 * on the N-only hooks, ledger facts still `failed` with no redelivery
 * queued, and pending intents still without an action; the runbook stops
 * the rollback while any do.
 */
final class WpRollbackDrain {

  public function __construct(private readonly IDDDConfig $config) {}

  /** @return array{ran: int, remaining: int, rounds: int} */
  public function run(int $maxRounds = 10): array {
    $ran = 0;
    $rounds = 0;
    // Action Scheduler is a runtime dependency of ddd-wp, not a static one
    // (phpstan scans only its procedural API): resolve the runner dynamically.
    $runner = class_exists('ActionScheduler') ? \call_user_func(['ActionScheduler', 'runner']) : null;
    $wakeups = $this->wakeups();
    while ($runner !== null && $rounds < $maxRounds) {
      // A redelivery Action Scheduler lost is scheduled again first, and
      // every intent without an action is projected again, so the drain
      // (or, on a legacy hook, the 0.6 winner) runs them too.
      WpLedgeredDelivery::restoreRedeliveries($this->config);
      $wakeups?->reproject($this->now(), 1000, true);
      $ids = $this->pending();
      if ($ids === []) {
        break;
      }
      $rounds++;
      foreach ($ids as $id) {
        $runner->process_action($id, 'wp ddd drain --before-rollback');
        $ran++;
      }
    }
    $wakeups?->reproject($this->now(), 1000, true);
    return [
      'ran' => $ran,
      'remaining' => count($this->pending())
        + $this->futureByReference()
        + WpLedgeredDelivery::orphanedRedeliveries($this->config)
        + ($wakeups?->unprojected() ?? 0),
      'rounds' => $rounds,
    ];
  }

  private function wakeups(): ?WpdbWakeupScheduler {
    if (!WpSchema::isV8($this->config) || !function_exists('as_has_scheduled_action')) {
      return null;
    }
    $scheduler = \TangibleDDD\Runtime\HostDefaults::for(\TangibleDDD\Runtime\Scheduling\IWakeupScheduler::class, $this->config);
    return $scheduler instanceof WpdbWakeupScheduler ? $scheduler : new WpdbWakeupScheduler($this->config);
  }

  private function now(): \DateTimeImmutable {
    $clock = \TangibleDDD\Runtime\HostDefaults::get(\TangibleDDD\Runtime\IClock::class);
    return ($clock instanceof \TangibleDDD\Runtime\IClock ? $clock : new \TangibleDDD\Runtime\SystemClock())->now();
  }

  /** @return list<int> */
  public function pending(): array {
    if (!function_exists('as_get_scheduled_actions')) {
      return [];
    }
    $ids = [];
    foreach (['ddd_redeliver', 'ddd_wakeup'] as $hook) {
      foreach ((array) as_get_scheduled_actions([
        'hook' => $this->config->hook($hook),
        'status' => 'pending', // ActionScheduler_Store::STATUS_PENDING
        'per_page' => -1,
      ], 'ids') as $id) {
        $ids[] = (int) $id;
      }
    }
    foreach ($this->byReference() as [$id, $due]) {
      if ($due === null || $due <= $this->now()->getTimestamp()) {
        $ids[] = $id;
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * By-reference integration actions (WpLargeEnvelope, D6) still pending
   * and due after now: N-only (a 0.6 winner cannot resolve them) and not
   * run early, because their delay is part of the fact. The runbook waits
   * for them.
   */
  public function futureByReference(): int {
    $now = $this->now()->getTimestamp();
    return count(array_filter($this->byReference(), static fn (array $a) => $a[1] !== null && $a[1] > $now));
  }

  /** @return list<array{0: int, 1: ?int}> [action id, due timestamp] of pending by-reference integration actions */
  private function byReference(): array {
    if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
      return [];
    }
    $store = \call_user_func(['ActionScheduler', 'store']);
    $prefix = $this->config->hook('integration_');
    $out = [];
    foreach ((array) as_get_scheduled_actions([
      'group' => $this->config->as_group('outbox'),
      'status' => 'pending',
      'per_page' => -1,
    ], 'ids') as $id) {
      $action = $store->fetch_action((string) $id);
      $args = $action->get_args();
      if (!str_starts_with((string) $action->get_hook(), $prefix) || !is_array($args[0] ?? null) || !WpLargeEnvelope::isReference($args[0])) {
        continue;
      }
      $date = $action->get_schedule()->get_date();
      $out[] = [(int) $id, $date === null ? null : $date->getTimestamp()];
    }
    return $out;
  }
}
