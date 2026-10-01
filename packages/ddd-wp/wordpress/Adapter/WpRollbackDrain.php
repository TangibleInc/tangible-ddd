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
 * - `{prefix}_ddd_wakeup` (ResumeRetry intents).
 *
 * Neither hook has a callback under 0.6, so whatever is still pending when
 * the winner switches back is failed by Action Scheduler and lost. Each
 * round first re-schedules redeliveries Action Scheduler lost
 * (WpLedgeredDelivery::restoreRedeliveries()). The report says how many
 * remain: pending actions plus ledger facts still `failed` with no
 * redelivery queued; the runbook stops the rollback while any do.
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
    while ($runner !== null && $rounds < $maxRounds) {
      // A redelivery Action Scheduler lost is scheduled again first, so the
      // drain runs it too.
      WpLedgeredDelivery::restoreRedeliveries($this->config);
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
    return [
      'ran' => $ran,
      'remaining' => count($this->pending()) + WpLedgeredDelivery::orphanedRedeliveries($this->config),
      'rounds' => $rounds,
    ];
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
    sort($ids);
    return $ids;
  }
}
