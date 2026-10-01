<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Events;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** Reference scenario: what the D1 effect's record() announces inside its transaction. */
final class ToyCharged extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly string $team_id, public readonly string $charge_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
