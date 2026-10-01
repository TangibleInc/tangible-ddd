<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/**
 * Register-then-check (D3, register 3.8 "a IPrecheckAwait::already_satisfied()
 * hook absorbs a fact that committed before suspension"). Implemented by the
 * LongProcess subclass.
 *
 * When a forward step suspends, the runner, still holding the process lock:
 *   1. commits the await, its alarm intent and the step checkpoint;
 *   2. dispatches the step's commands;
 *   3. calls already_satisfied() with the await, unless a fact delivered
 *      during step 2 already moved the process on.
 * A non-null result resumes the process at once, exactly as the awaited fact
 * would have (the alarm is cancelled in the resuming save). The step after
 * the await receives PrecheckSatisfied::$resume_argument, or the mechanism's
 * resume_argument(null) for PrecheckSatisfied::now(); type its second
 * parameter accordingly.
 *
 * A fact that arrives afterwards finds no suspended process for its key: the
 * resume subscriber completes quietly (ProcessRunner::resume_with_outcome()
 * reports it as unheard by any await; nothing throws, nothing retries).
 *
 * The check reads published state (a query); it must not dispatch commands.
 * An exception is a step failure (retry policy, then compensation).
 */
interface IPrecheckAwait {

  public function already_satisfied(IAwaitMechanism $await): ?PrecheckSatisfied;
}
