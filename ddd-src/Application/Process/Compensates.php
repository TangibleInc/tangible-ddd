<?php

namespace TangibleDDD\Application\Process;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Compensates {
  public function __construct(
    /**
     * Forward step method name this method compensates.
     *
     * Example: #[Compensates('charge_card')]
     */
    public readonly string $step
  ) {}
}


