<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A plain fact with one listener (RbConsumer::listen()); optionally delayed or unique. */
class RbNote extends IntegrationEvent {

  public function __construct(
    public readonly string $text = '',
    public readonly int $delay_seconds = 0,
    public readonly bool $unique = false,
  ) {}

  public function delay(): int {
    return $this->delay_seconds;
  }

  public function is_unique(): bool {
    return $this->unique;
  }

  protected static function prefix(): string {
    return RbConsumer::PREFIX;
  }
}
