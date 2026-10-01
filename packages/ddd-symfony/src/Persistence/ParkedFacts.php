<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Runtime\Wakeup\IProcessWakeTarget;

/**
 * Gives a parked fact resume (AW2) its fact back before it wakes. The
 * ddd_wakeups row holds the fact (schema 011), but the Messenger projection
 * of the intent (ProcessWakeupMessage) does not carry it, so the intent the
 * wakeup handler rebuilds has `fact` null. Woken like that, a ResumeRetry
 * with expected status `suspended` would be the repeat of an await TIMEOUT.
 *
 * For a ResumeRetry without a fact this reads the row by key: a stored fact
 * is put back on the intent; a key in the parked-fact format
 * (WakeupIntent::resume_fact(), `...:fact-{event_id}`) whose row has no fact
 * any more is not woken at all (another message completed it, or the row
 * was repaired), so it never runs as a timeout. Every other intent passes
 * through unchanged.
 *
 * @internal wired by the bundle around each consumer's process wake target
 */
final class ParkedFacts implements IProcessWakeTarget {

  public function __construct(
    private readonly DbalWakeupScheduler $wakeups,
    private readonly IProcessWakeTarget $target,
  ) {}

  public function wake(WakeupIntent $intent): void {
    if ($intent->kind === WakeKind::ResumeRetry && $intent->fact === null) {
      $fact = $this->wakeups->find($intent->key)?->fact;
      if ($fact !== null) {
        $intent = new WakeupIntent(
          $intent->kind, $intent->consumer, $intent->process_id, $intent->step_index, $intent->expected_status,
          $intent->due_at, $intent->key, $fact,
        );
      } elseif (self::is_parked_fact($intent->key)) {
        return;
      }
    }
    $this->target->wake($intent);
  }

  private static function is_parked_fact(string $key): bool {
    return preg_match('/^resume_retry:\d+:\d+:suspended:0:fact-/', $key) === 1;
  }
}
