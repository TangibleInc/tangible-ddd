<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use TangibleDDD\Application\Commands\ICommand;

/**
 * A command with a side effect outside the database (D1, register 3.8).
 * Interface only in wave 1; EffectMiddleware (between Correlation and
 * Transaction) lands in wave 4.
 *
 * - idempotencyKey(): the journal key. The command id is for tracing only.
 * - perform(): runs OUTSIDE the transaction; its result is journaled.
 * - record(): runs INSIDE the transaction with the (possibly journaled) result.
 * - failureCommand(): dispatched ONCE by the core delivery invoker when this
 *   command's subscriber exhausts its handler budget (counted in the
 *   delivery ledger), never from a transport failure event. Null = nothing.
 *
 * Error behaviour: perform() and record() may throw; the delivery or step
 * retry policy decides what happens next. Inside a process step, perform
 * retries follow the step's retry policy (default 0 → compensate).
 */
interface IExternalEffectCommand extends ICommand {

  public function idempotencyKey(): string;

  public function perform(): EffectResult;

  public function record(EffectResult $r): void;

  public function failureCommand(\Throwable $last): ?ICommand;
}
