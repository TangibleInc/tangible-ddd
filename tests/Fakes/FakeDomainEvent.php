<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;

class FakeDomainEvent extends DomainEvent {
  public function __construct(
    public readonly int $entity_id = 1,
    public readonly string $action_type = 'created'
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array {
    return ['entity_id' => $this->entity_id, 'action_type' => $this->action_type];
  }
}
