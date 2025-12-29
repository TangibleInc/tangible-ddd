<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/**
 * Execution status for a behaviour step.
 *
 * "completed", "waiting", and "skipped" are essentially "success" statuses.
 * "failed" impedes advancing and triggers retry/reschedule logic in the runner.
 */
enum BehaviourExecutionStatus: string {
  case completed = 'completed';
  case batched = 'batched';
  case forked = 'forked';

  case waiting = 'waiting';
  case skipped = 'skipped';

  case failed = 'failed';

  case cancelled = 'cancelled';
  case preempted = 'preempted';
}


