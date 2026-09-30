<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * Dispatches domain events to listeners.
 *
 * In WordPress, this fires do_action() hooks.
 * Other platforms could implement pub/sub, event sourcing, etc.
 */
interface IDomainEventDispatcher {
  /**
   * Dispatch a domain event to all registered listeners.
   *
   * @param IDomainEvent $event The event to dispatch
   */
  public function dispatch(IDomainEvent $event): void;
}
