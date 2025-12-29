<?php

namespace TangibleDDD\Domain\Shared;

use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * Interface for aggregates that record domain events.
 */
interface IRecordsDomainEvents {
  /**
   * Record a domain event on this aggregate.
   */
  public function event(IDomainEvent $event): void;

  /**
   * Pull all recorded events and clear the internal list.
   *
   * @return IDomainEvent[]
   */
  public function pull_events(): array;
}
