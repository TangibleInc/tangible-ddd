<?php

namespace TangibleDDD\Domain\Events;

/**
 * Shared root of the event partition: name/prefix machinery only.
 * DomainEvent (raisable) and IntegrationEvent (derived-only record)
 * both extend this and NOTHING else is shared between them.
 */
abstract class Event {

  /** Consumer provides the prefix via generated base class. */
  abstract protected static function prefix(): string;

  /**
   * Short event name. Default: derive from class name (UserEarned -> user_earned).
   */
  public static function name(): string {
    $class = (new \ReflectionClass(static::class))->getShortName();
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
  }
}
