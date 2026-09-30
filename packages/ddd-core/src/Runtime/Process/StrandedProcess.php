<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/** A `running`/`scheduled` process with no live intent past the threshold (5.3 step 5). */
final class StrandedProcess {

  public function __construct(
    public readonly int $processId,
    public readonly string $processClass,
    public readonly string $status,
    public readonly int $stepIndex,
    public readonly \DateTimeImmutable $updatedAt,
  ) {}
}
