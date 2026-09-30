<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Abstraction for publishing integration events to an external or internal bus.
 */
interface IIntegrationEventBus {
  /**
   * Publish an integration event.
   *
   * @param IIntegrationEvent $event The integration event to publish.
   */
  public function publish(IIntegrationEvent $event): void;
}
