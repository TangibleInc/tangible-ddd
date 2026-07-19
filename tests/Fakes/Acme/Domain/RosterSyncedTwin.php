<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

/** The announced record — scalar payload, and the stamps live HERE. */
#[Touches(Op::Updated, Roster::class)]
class RosterSyncedTwin extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly int $roster_id,
    public readonly int $added,
  ) {}

  protected static function prefix(): string { return 'acme'; }

  public function payload(): array { return $this->integration_payload(); }
}
