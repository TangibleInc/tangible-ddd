<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * Routes domain events to appropriate destinations.
 *
 * 1. All domain events go to the dispatcher (WordPress hooks).
 * 2. Announcing events additionally send their record to the bus.
 */
final class EventRouter {
  public function __construct(
    private readonly IDomainEventDispatcher $dispatcher,
    private readonly IIntegrationEventBus $bus
  ) {}

  public function publish(IDomainEvent $event): void {
    $this->dispatcher->dispatch($event);

    if ($event instanceof IAnnouncesIntegration) {
      $this->bus->publish($event->to_integration());
    }
  }
}
