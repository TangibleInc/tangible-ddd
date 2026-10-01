<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process\Repair;

use TangibleDDD\Application\Commands\Command;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/**
 * Operator repair (register 3.10, 5.3 step 5; WP8-10): re-run the current
 * step of a stranded process, i.e. a `running` (or `scheduled`) row with no
 * live intent past the stranded threshold, whose worker died mid-step.
 *
 * The command never runs a step itself (a step dispatches commands, and a
 * command cannot dispatch commands): it writes a durable wake in its own
 * transaction, and the next drain / worker runs the step under the process
 * lock. A `running` row gets a ResumeRetry intent at its current version
 * (stale-safe: a no-op if anything moves the row first); a `scheduled` row
 * gets a fresh Continue intent. The re-run dispatches the step's commands
 * with the same deterministic ids (D13), so idempotent handlers and the D1
 * journal absorb what the dead run already did.
 *
 * Guards (C23): the process must be in the stranded scan (status, no live
 * intent, older than the threshold); its process lock must be free (no
 * worker holds it); with $expected_version, the row must still be at that
 * version (what the operator looked at). Otherwise ProcessNotStranded.
 */
final class ResumeStrandedProcess extends Command implements ITransactionalCommand {

  public function __construct(
    public readonly string $consumer_prefix,
    public readonly int $process_id,
    public readonly ?int $expected_version = null,
  ) {}
}
