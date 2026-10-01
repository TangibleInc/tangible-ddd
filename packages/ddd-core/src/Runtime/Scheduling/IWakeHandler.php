<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * Runs one due wakeup (register 3.6). ProcessRunner implements it for the
 * process kinds (Continue, Timeout, ResumeRetry); Drain::run_once() hands it
 * each intent it claimed from the IWakeupScheduler. Wave 3 addition
 * (wave3-core CR-W3C-3).
 *
 * Error behaviour: a stale intent (the process moved on, `expected_status`
 * or `step_index` no longer match under the lock) is a quiet no-op. Lock
 * contention and transient failures THROW, and the caller re-queues the
 * claimed intent with backoff (IWakeupScheduler::retry_later); inside wake()
 * the runner never schedules a second ResumeRetry of its own, so a wake is
 * re-queued exactly once. A kind the handler does not run throws
 * \LogicException.
 */
interface IWakeHandler {

  public function wake(WakeupIntent $intent): void;
}
