<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

class FakeResolvedEvent extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration {
  use IntegrationBehaviour;

  public function __construct(
    public readonly int $request_id,
    public readonly FakeOutcome $outcome,
    public readonly \DateTimeImmutable $resolved_at,
    public readonly array $extra = [],
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}
