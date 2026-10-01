<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use TangibleDDD\Application\Commands\ICommand;

/**
 * A command with a side effect outside the database (D1, register 3.8).
 * EffectMiddleware (between Correlation and Transaction, wave 4) runs it:
 * no handler class is needed, record() is the handling, and the bus returns
 * the EffectResult.
 *
 * - idempotency_key(): the journal key. The command id is for tracing only.
 * - perform(): runs OUTSIDE the transaction; its result is journaled.
 * - record(): runs INSIDE the transaction with the (possibly journaled) result.
 * - failure_command(): dispatched ONCE by the core delivery invoker when this
 *   command's subscriber exhausts its handler budget (counted in the
 *   delivery ledger), never from a transport failure event. Null = nothing.
 *   Its command id is uuid5(event_id, "{subscriber}#failure"), so a
 *   re-fired compensation repeats it.
 *
 * Error behaviour: perform() and record() may throw; the delivery or step
 * retry policy decides what happens next. Inside a process step, perform
 * retries follow the step's retry policy (default 0 → compensate).
 */
interface IExternalEffectCommand extends ICommand {

  public function idempotency_key(): string;

  public function perform(): EffectResult;

  public function record(EffectResult $r): void;

  public function failure_command(\Throwable $last): ?ICommand;
}
