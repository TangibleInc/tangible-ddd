<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use Attribute;

/**
 * Step retry policy (D1 inside process steps, register 3.8 "inside a
 * process step, D1 perform retries follow the step's retry policy (default
 * 0 → compensate)").
 *
 * Without the attribute a failing forward step compensates at once (0.6).
 * With it, a failure (the step method threw, or one of its commands threw
 * during dispatch) re-runs the step up to $attempts more times: the runner
 * withdraws the step's await if it had suspended, persists the process
 * `scheduled` with a durable Continue intent due after $backoff_seconds, and
 * the continuation re-runs the step from its input payload. Only when the
 * retries are used up does the process compensate.
 *
 * A re-run dispatches the step's commands again with the same deterministic
 * command ids (D13), and step_ref() mints the same refs, so effect commands
 * (IExternalEffectCommand) find their journaled result by idempotency key
 * and do not perform again. Plain commands must be idempotent.
 *
 * Attempts are counted per step in the persisted step state
 * (LongProcess::step_attempts()). Compensation methods are not retried.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RetryStep {

  public function __construct(
    public readonly int $attempts = 0,
    public readonly int $backoff_seconds = 0,
  ) {
    if ($attempts < 0 || $backoff_seconds < 0) {
      throw new \InvalidArgumentException('RetryStep attempts and backoff_seconds must be >= 0');
    }
  }
}
