<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/** A `running`/`scheduled` process with no live intent past the threshold (5.3 step 5). */
final class StrandedProcess {

  public function __construct(
    public readonly int $process_id,
    public readonly string $process_class,
    public readonly string $status,
    public readonly int $step_index,
    public readonly \DateTimeImmutable $updated_at,
  ) {}
}
