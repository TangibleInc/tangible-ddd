<?php

namespace TangibleDDD\Application\Outbox;

/**
 * Result of a batch processing run.
 */
final class ProcessingResult {
  public function __construct(
    public readonly int $completed,
    public readonly int $failed,
    public readonly int $dlq,
    public readonly int $total,
  ) {}
}
