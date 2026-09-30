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
   * THE FRAMEWORK'S HARVEST VERB. Its only caller is
   * EventsUnitOfWork::collect_from(), reached through the (final) repository
   * save(). Consumer code must never call it — walking off with the diary is
   * how events dodge the seal. To clear without harvesting, use
   * IntegrationConformance::pull_events_violations() is
   * the fence.
   *
   * @return IDomainEvent[]
   */
  public function pull_events(): array {
    $events = $this->events;
    $this->events = [];
    return $events;
  }

}
