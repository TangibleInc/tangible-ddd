<?php

namespace TangibleDDD\Domain\Shared;

use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * Trait for aggregates to record domain events.
 */
trait RecordsDomainEvents {
  /** @var IDomainEvent[] */
  private array $events = [];

  /**
   * Record a domain event.
   */
  public function event(IDomainEvent $event): void {
    $this->events[] = $event;
  }

  /**
   * Pull all recorded events and clear the list.
   *
   * @return IDomainEvent[]
   */
  public function pull_events(): array {
    $events = $this->events;
    $this->events = [];
    return $events;
  }
}
