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
    $this->events->seal();

    // Drain repeatedly: publishing a domain event dispatches its synchronous
    // handlers, which may write aggregates and thereby record further events.
    // Those land in the queue mid-drain and must be flushed too. The seal
    // guarantees only integration events can be recorded past this point, so
    // the cascade terminates at the outbox.
    while (!empty($events = $this->events->drain())) {
      foreach ($events as $event) {
        if ($event instanceof IDomainEvent) {
          $this->router->publish($event);
        }
      }
    }

    return $result;
  }
}
