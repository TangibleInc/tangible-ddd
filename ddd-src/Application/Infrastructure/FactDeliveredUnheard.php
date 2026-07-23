<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Application\Outbox\OutboxEntry;

/**
 * An outbox entry was delivered with ZERO registered listeners — the action
 * fired into silence. Not a failure (the contract is fire-and-forget; the
 * entry completes normally) but a strong smell: a renamed action, a consumer
 * deactivated mid-flight, or a subscriber that never wired up.
 *
 * Fires {prefix}_fact_delivered_unheard + the global
 * tangible_ddd_fact_delivered_unheard (see InfrastructureEvent::dispatch).
 * The condition is also noted on the outbox row's error_history so it
 * survives for the dashboard without any schema change.
 */
final class FactDeliveredUnheard extends InfrastructureEvent {

  public function __construct(OutboxEntry $entry) {
    parent::__construct(
      $entry,
      $entry->correlation_id,
      $entry->event_id,
      'integration_event',
    );
  }

  public static function action(): string {
    return 'fact_delivered_unheard';
  }

  public function entry(): OutboxEntry {
    return $this->subject;
  }
}
