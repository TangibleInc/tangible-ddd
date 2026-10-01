<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\Billing\Events;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** Consumer `bil`'s own fact (its prefix comes from ConsumerRegistry by namespace). */
final class WidgetBilled extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly string $widget_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
