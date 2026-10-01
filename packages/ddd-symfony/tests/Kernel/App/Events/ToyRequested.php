<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Events;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** Reference scenario (E section 10): the fact that starts the toy saga (MembershipGranted's role). */
final class ToyRequested extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly string $team_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
