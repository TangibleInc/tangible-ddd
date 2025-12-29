<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * WordPress implementation of domain event dispatcher.
 *
 * Fires domain events as WordPress actions via do_action().
 */
class WordPressEventDispatcher implements IDomainEventDispatcher {
  public function dispatch(IDomainEvent $event): void {
    do_action($event::action(), ...$event->payload());
  }
}
