<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Routes domain events to appropriate destinations.
 *
 * 1. All domain events go to the dispatcher (WordPress hooks)
 * 2. Integration events additionally go to the bus (outbox)
 */
final class EventRouter {
  public function __construct(
    private readonly IDomainEventDispatcher $dispatcher,
    private readonly IIntegrationEventBus $bus
  ) {}

  public function publish(IDomainEvent $event): void {
    $this->dispatcher->dispatch($event);

    if ($event instanceof IIntegrationEvent) {
      $this->bus->publish($event);
    }
  }
}
