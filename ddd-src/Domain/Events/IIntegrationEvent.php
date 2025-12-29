<?php

namespace TangibleDDD\Domain\Events;

interface IIntegrationEvent extends IDomainEvent {
  /**
   * WordPress action name for the integration (async) version of this event.
   */
  public static function integration_action(): string;

  /**
   * Serializable payload for ActionScheduler/outbox.
   * Should contain only scalar values.
   */
  public function integration_payload(): array;

  /**
   * Delay in seconds before processing this event.
   */
  public function delay(): int;

  /**
   * Whether to deduplicate pending events of this type.
   */
  public function is_unique(): bool;
}
