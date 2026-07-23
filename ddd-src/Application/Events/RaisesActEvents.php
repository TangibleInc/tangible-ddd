<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * The blessed act-level raise (hardening item 4) — a coordinator's
 * $this->event(), delegating to the live EventsUnitOfWork.
 *
 * Facts about an aggregate's state belong on the aggregate — raise them
 * there and let the repository harvest. This lane is for ACT-level
 * coordination facts only (reschedules, process starts). If your event
 * names something that happened to a thing with a repository, you are in
 * the wrong place.
 *
 * Every call site is a conscious, reviewed decision:
 * IntegrationConformance::handler_raised_events() scans for them and the
 * consumer suite's allowlist IS the review. Events recorded here carry
 * origin 'act' in the act's audit record (item 5), so the ledger shows the
 * lane in production too. This trait supersedes consumer-local static
 * facades (e.g. cred's Events): the unit of work is container-managed
 * state and must be injected, never cached statically.
 */
trait RaisesActEvents {

  /**
   * Raise an act-level coordination fact on the current unit of work.
   */
  protected function event(IDomainEvent $event): void {
    $events = $this->act_events();
    if ($events === null) {
      throw new \LogicException(sprintf(
        '%s raised an act event but no EventsUnitOfWork was injected — pass the '
        . 'consumer\'s live tangible.events_unit_of_work service to the constructor.',
        static::class
      ));
    }
    $events->record($event);
  }

  /**
   * The live per-command unit of work, or null when the adopter was built
   * without one (event() then throws instead of silently dropping moments).
   */
  abstract protected function act_events(): ?EventsUnitOfWork;
}
