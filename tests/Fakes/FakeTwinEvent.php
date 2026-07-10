<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

class FakeTwinEvent extends IntegrationEvent {
  public function __construct(public readonly int $entity_id) {}
  protected static function prefix(): string { return 'test'; }
}
