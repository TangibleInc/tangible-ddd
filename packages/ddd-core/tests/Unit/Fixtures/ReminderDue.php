<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A delayed, unique fact (delay 90 s). */
final class ReminderDue extends IntegrationEvent {

  public function __construct(public readonly int $user_id = 1) {}

  public function delay(): int {
    return 90;
  }

  public function is_unique(): bool {
    return true;
  }

  protected static function prefix(): string {
    return 'acme';
  }
}
