<?php

namespace TangibleDDD\Domain\Events;

/**
 * The record contract: an event composed of reversible values, engineered so
 * instances exist on BOTH sides of the ActionScheduler hop.
 *
 * SEVERED from IDomainEvent (0.2.0 partition): a class implementing ONLY this
 * interface cannot be raised — EventsUnitOfWork::record() types IDomainEvent.
 * Self-publishers implement both (extends DomainEvent + this interface).
 */
interface IIntegrationEvent {

  public static function name(): string;

  /** WordPress action name for the integration (async) surface. */
  public static function integration_action(): string;

  /** Named array of reversible scalars. @throws NonReversibleValue */
  public function integration_payload(): array;

  /** The return ticket — total for every conforming record. */
  public static function from_payload(array $payload): static;

  public function delay(): int;
  public function is_unique(): bool;
}
