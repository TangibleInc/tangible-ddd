<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/**
 * Extends the FRAMEWORK bases directly — no stamped consumer middle class.
 * prefix() comes from owner_of(static::class) (0.2.5c).
 */
class WidgetShipped extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly int $widget_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
