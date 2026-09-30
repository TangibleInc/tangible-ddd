<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Events;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/**
 * A self-publishing fact. No stamped prefix(): the consumer is resolved from
 * ConsumerRegistry by namespace, as in a real app.
 */
final class WidgetRegistered extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent, WidgetFact {
  use IntegrationBehaviour;

  public function __construct(public readonly string $widget_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
