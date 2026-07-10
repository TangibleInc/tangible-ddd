<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

class FakeIntegrationEvent extends IntegrationEvent {
  public function __construct(
    public readonly int $entity_id = 1,
    public readonly string $action_type = 'synced'
  ) {}

  protected static function prefix(): string { return 'test'; }
}
