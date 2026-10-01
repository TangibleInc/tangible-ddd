<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process\Repair;

use TangibleDDD\Application\Commands\Command;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/**
 * Operator repair (register 3.10, 5.3 step 5; WP8-10): give up on a stranded
 * process.
 *
 * - compensate = false: the row becomes `failed` with
 *   "Failed by operator: <reason>"; nothing runs.
 * - compensate = true: the row enters compensation (the steps completed
 *   before the stranded one are undone, in reverse) and becomes `scheduled`
 *   with a Continue intent; the next drain runs the compensations under the
 *   lock and the process ends `failed`. The stranded step itself is not
 *   compensated, as with any failing step.
 *
 * Same guards as ResumeStrandedProcess (stranded scan, free lock, optional
 * expected version); otherwise ProcessNotStranded.
 */
final class FailStrandedProcess extends Command implements ITransactionalCommand {

  public function __construct(
    public readonly string $consumer_prefix,
    public readonly int $process_id,
    public readonly string $reason,
    public readonly bool $compensate = false,
    public readonly ?int $expected_version = null,
  ) {}
}
