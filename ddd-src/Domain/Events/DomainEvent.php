<?php

namespace TangibleDDD\Domain\Events;

/**
 * Base class for domain events — the RAISABLE species. Validity is bounded
 * by the request: instances die pre-ActionScheduler, always.
 *
 * Consumer plugins extend this in their generated DomainEvent base,
 * providing the prefix() method.
 */
abstract class DomainEvent extends Event implements IDomainEvent {

  /**
   * WordPress action name for domain event publishing.
   */
  public static function action(): string {
    return static::prefix() . '_domain_' . static::name();
  }

  /**
   * Event payload. Override in concrete events.
   */
  abstract public function payload(): array;
}
