<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Application\Exceptions\DomainEventAfterSealException;
use TangibleDDD\Domain\Events\AlreadyIntegrated;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;

/**
 * Per-command event buffer. Repositories should collect domain events into this,
 * and a post-handler middleware should publish and then clear it.
 *
 * Lifecycle: the publishing middleware resets this before the command handler
 * runs (open phase — anything may be recorded), then seals it once the handler
 * returns (sealed phase — only integration events may be recorded, as those are
 * the only events handlers are permitted to emit).
 */
class EventsUnitOfWork {

  /** @var IDomainEvent[] */
  private array $queued = [];

  /** @var IDomainEvent[] */
  private array $published = [];

  private bool $sealed = false;

  public function reset(): void {
    $this->queued = [];
    $this->published = [];
    $this->sealed = false;
  }

  /**
   * Close the open phase. After this, only INTEGRABLE events may be recorded
   * (those implementing IAnnouncesIntegration — they announce a fact routed to
   * the bus); a plain, non-integrable domain event recorded past the seal throws.
   */
  public function seal(): void {
    $this->sealed = true;
  }

  public function record(IDomainEvent $event): void {
    // Re-raise guard: keyed on IIntegrationEvent because PublishedFacts tracks
    // published FACTS (self-publisher instances). Distinct from the seal.
    if ($event instanceof IIntegrationEvent && null !== $published_as = PublishedFacts::id_of($event)) {
      throw new AlreadyIntegrated(get_class($event), $published_as);
    }
    // The seal exempts INTEGRABLE events — those that announce an integration
    // and will be routed to the bus by EventRouter (which gates on exactly this
    // interface). Keyed on IAnnouncesIntegration, NOT IIntegrationEvent: the
    // latter is the scalar twin/record contract (severed from IDomainEvent at
    // the 0.2.0 split, so a pure twin can't even reach record()), while
    // IAnnouncesIntegration is the raisable "can be turned into a fact" marker
    // — covering both self-publishers and twin-style announcers. This mirrors
    // EventRouter's routing gate: raised past the seal ⟹ routed to the bus.
    if ($this->sealed && !$event instanceof IAnnouncesIntegration) {
      throw new DomainEventAfterSealException(get_class($event));
    }
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
