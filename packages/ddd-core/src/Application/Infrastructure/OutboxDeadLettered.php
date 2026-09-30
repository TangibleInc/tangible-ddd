<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Application\Outbox\OutboxEntry;

/**
 * An outbox event exhausted its retries and was dead-lettered — TERMINAL.
 *
 * Fires once per event (the final attempt). The actionable signal: retries are
 * spent, a human/automation must act (this is what wakes escalation).
 *
 * Carries the dead event as causation (it is the parent of any reaction) and
 * the entry's correlation, so a listener rejoins the original trace. The
 * $final_error is the TRUE final exception (the DLQ row's own final_error is
 * stale — see move_to_dlq), passed in by the processor that caught it.
 */
final class OutboxDeadLettered extends InfrastructureEvent {

  public function __construct(
    OutboxEntry $entry,
    public readonly string $final_error = '',
  ) {
    parent::__construct(
      $entry,
      $entry->correlation_id,
      $entry->event_id,
      'integration_event',
    );
  }

  public static function action(): string {
    return 'outbox_dlq';
  }

  public function entry(): OutboxEntry {
    return $this->subject;
  }
}
