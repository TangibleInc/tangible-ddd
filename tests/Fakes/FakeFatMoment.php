<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;

class FakeFatMoment extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly object $entity) {}
  protected static function prefix(): string { return 'test'; }
  public function payload(): array { return [$this->entity]; }
  public function to_integration(): FakeTwinEvent {
    return new FakeTwinEvent(entity_id: (int) $this->entity->id);
  }
}
