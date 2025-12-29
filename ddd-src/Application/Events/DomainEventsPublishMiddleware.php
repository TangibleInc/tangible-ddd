<?php

namespace TangibleDDD\Application\Events;

use League\Tactician\Middleware;
use TangibleDDD\Domain\Events\IDomainEvent;

final class DomainEventsPublishMiddleware implements Middleware {
  public function __construct(
    private readonly EventsUnitOfWork $events,
    private readonly EventRouter $router
  ) {}

  public function execute($command, callable $next) {
    $this->events->reset();

    $result = $next($command);

    foreach ($this->events->drain() as $event) {
      if ($event instanceof IDomainEvent) {
        $this->router->publish($event);
      }
    }

    return $result;
  }
}
