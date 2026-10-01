<?php

namespace TangibleDDD\Domain\Events;

interface IDomainEvent {
  /**
   * Short name for this event type (e.g., 'user_earned').
   */
  public static function name(): string;

  /**
   * WordPress action name for this event.
   * Consumer's base class provides the prefix.
   */
  public static function action(): string;

  /**
   * Event payload for publishing.
   */
  public function payload(): array;
}
