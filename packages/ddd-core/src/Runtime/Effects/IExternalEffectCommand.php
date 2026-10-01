<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * A command with a side effect outside the database (D1, register 3.8)
 * that performs and records itself. EffectMiddleware (between Correlation
 * and Transaction, wave 4) runs it: no handler class is needed, record()
 * is the handling, and the bus returns the EffectResult.
 *
 * - idempotency_key(), failure_command(): see IEffectCommand (wave 5 moved
 *   the two declarations there, unchanged).
 * - perform(): runs OUTSIDE the transaction; its result is journaled.
 * - record(): runs INSIDE the transaction with the (possibly journaled) result.
 *
 * An effect that needs collaborators (a provider client, a repository) is an
 * IEffectCommand with an IExternalEffectHandler instead (E1, wave 5).
 *
 * Error behaviour: perform() and record() may throw; the delivery or step
 * retry policy decides what happens next. Inside a process step, perform
 * retries follow the step's retry policy (default 0 → compensate).
 */
interface IExternalEffectCommand extends IEffectCommand {

  public function perform(): EffectResult;

  public function record(EffectResult $r): void;
}
