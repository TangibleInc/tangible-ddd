<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

final class UserJoined extends IntegrationEvent {

  public function __construct(public readonly int $user_id = 1) {}

  protected static function prefix(): string {
    return 'acme';
  }
}
