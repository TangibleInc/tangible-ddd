<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;

/**
 * Per-command event buffer. Repositories should collect domain events into this,
 * and a post-handler middleware should publish and then clear it.
 */
class EventsUnitOfWork {

  /** @var IDomainEvent[] */
  private array $queued = [];

  /** @var IDomainEvent[] */
  private array $published = [];

  public function reset(): void {
    $this->queued = [];
    $this->published = [];
  }

  public function record(IDomainEvent $event): void {
    $this->queued[] = $event;
  }

  public function collect_from(IRecordsDomainEvents $aggregate): void {
    $events = $aggregate->pull_events();

    foreach ($events as $e) {
      $this->record($e);
    }
  }

  /**
   * Drain pending events for publishing and append them to the published log.
   *
   * @return IDomainEvent[]
   */
  public function drain(): array {
    $events = $this->queued;
    $this->queued = [];
    array_push($this->published, ...$events);
    return $events;
  }

  /**
   * @return IDomainEvent[]
   */
  public function published(): array {
    return $this->published;
  }
}
