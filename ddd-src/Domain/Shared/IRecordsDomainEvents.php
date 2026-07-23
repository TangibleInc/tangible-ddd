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
   * Framework harvest verb — called by EventsUnitOfWork::collect_from()
   * only. Consumers clearing a diary use discard_events() instead.
   *
   * @return IDomainEvent[]
   */
  public function pull_events(): array;

  /**
   * Clear recorded events without returning them (reconstitution: loading
   * an aggregate must not re-raise its stored moments).
   */
  public function discard_events(): void;
}
