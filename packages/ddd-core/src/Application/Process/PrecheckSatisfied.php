<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/** The answer of a satisfied IPrecheckAwait: what the post-await step receives. */
final class PrecheckSatisfied {

  private function __construct(
    public readonly mixed $resume_argument,
    public readonly bool $use_mechanism_argument,
  ) {}

  /** The post-await step receives $resume_argument. */
  public static function with(mixed $resume_argument): self {
    return new self($resume_argument, false);
  }

  /** The post-await step receives the mechanism's resume_argument(null). */
  public static function now(): self {
    return new self(null, true);
  }
}
