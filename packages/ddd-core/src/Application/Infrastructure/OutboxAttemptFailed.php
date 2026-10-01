<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Application\Outbox\OutboxEntry;

/**
 * An outbox delivery attempt failed but has retries left — TRANSIENT.
 *
 * Fires on each bounce (attempts 1..max-1); the event stays queued and will be
 * retried with backoff. This is the flakiness / retry-pressure signal — feed it
 * to metrics, do NOT alert a human on it (that is OutboxDeadLettered's job).
 */
final class OutboxAttemptFailed extends InfrastructureEvent {

  public function __construct(
    OutboxEntry $entry,
    public readonly int $attempt,
    public readonly int $max_attempts,
    public readonly string $error,
  ) {
    parent::__construct(
      $entry,
      $entry->correlation_id,
      $entry->event_id,
      'integration_event',
    );
  }

  public static function action(): string {
    return 'outbox_attempt_failed';
  }

  public function entry(): OutboxEntry {
    return $this->subject;
  }
}
