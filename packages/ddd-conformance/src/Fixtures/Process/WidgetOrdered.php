<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/**
 * The igniting fact: raisable and announced (a scenario can publish it
 * through the host bus and relay it). It ignites OrderedWidgetProcess and
 * AwaitOrderProcess waits for it.
 */
final class WidgetOrdered extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly string $widget_id) {}

  public function payload(): array {
    return $this->integration_payload();
  }

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
