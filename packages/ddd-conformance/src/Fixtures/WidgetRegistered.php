<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/**
 * A self-publishing fact: raisable (so a reaction may record it past the
 * seal) and announced to the outbox. `delay_seconds` feeds delay() so one
 * class covers delivery.delayed-once as well.
 */
final class WidgetRegistered extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly string $widget_id,
    public readonly int $delay_seconds = 0,
  ) {}

  public function payload(): array {
    return $this->integration_payload();
  }

  public function delay(): int {
    return $this->delay_seconds;
  }

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
