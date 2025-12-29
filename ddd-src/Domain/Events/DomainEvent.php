<?php

namespace TangibleDDD\Domain\Events;

/**
 * Base class for domain events.
 *
 * Consumer plugins extend this class in their generated DomainEvent base,
 * providing the prefix() method.
 */
abstract class DomainEvent implements IDomainEvent {
  /**
   * Consumer provides the prefix via generated base class.
   */
  abstract protected static function prefix(): string;

  /**
   * Short event name. Override in concrete events.
   * Default: derive from class name (UserEarned -> user_earned).
   */
  public static function name(): string {
    $class = (new \ReflectionClass(static::class))->getShortName();
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
  }

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
