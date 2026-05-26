<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

class FakeIntegrationEvent extends IntegrationEvent {
  public function __construct(
    public readonly int $entity_id = 1,
    public readonly string $action_type = 'synced'
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array {
    return ['entity_id' => $this->entity_id, 'action_type' => $this->action_type];
  }

  public static function from_payload(array $payload): static {
    return new static(
      entity_id: (int) ($payload['entity_id'] ?? 0),
      action_type: (string) ($payload['action_type'] ?? '')
    );
  }
}
