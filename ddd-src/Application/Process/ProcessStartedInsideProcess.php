<?php

namespace TangibleDDD\Application\Process;

use TangibleDDD\Application\Exceptions\ApplicationException;

/**
 * ProcessRunner::start() was called inside another process's wake bracket —
 * a saga step trying to spawn a child directly.
 *
 * A direct spawn would make the child's birth an un-audited side effect of
 * a step ("coordinators sequence, commands act" — violated at the moment of
 * birth). The legal spelling routes through the existing kinds: the parent
 * step dispatches a Command (the fan-out decision, audited), its handler
 * announces a Fact, and the child declares #[StartsOn(ThatFact::class)] —
 * every hop recorded, replay-safe (ignited_by dedup), human-door included.
 */
final class ProcessStartedInsideProcess extends ApplicationException {

  public function __construct(string $process_class, string $inside_process_id) {
    parent::__construct(sprintf(
      '%s started inside process #%s — a step must not spawn a process '
      . 'directly. Dispatch a command whose handler announces a fact, and '
      . 'give the child #[StartsOn(...)].',
      $process_class,
      $inside_process_id
    ));
  }
}
