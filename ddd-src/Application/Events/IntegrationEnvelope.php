<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Application\Correlation\CorrelationContext;

/**
 * The wire form of an integration event — the last member of the
 * Integration* family (Event = the record, Behaviour = the codec,
 * Listener = the consumer, action = the hook name, Envelope = this).
 *
 * wrap() smears the journey keys into the payload bag for transport;
 * unwrap() is the single place they are separated back out. Only PUBLISHED
 * events — facts, in the ontology — ever ride an envelope: identity and
 * story membership exist from the moment the outbox row is written.
 *
 * Used by the OutboxProcessor (wrap), the WP integration_action() helper,
 * the listener ceremony, and the process runner's wake path (unwrap).
 */
final class IntegrationEnvelope {

  private function __construct(
    public readonly array $payload,
    public readonly ?string $correlation_id,
    public readonly ?int $sequence,
    public readonly ?string $event_id,
  ) {}

  /** The send side: journey keys into the bag. Inverse of unwrap(). */
  public static function wrap(array $payload, ?string $correlation_id, ?int $sequence, ?string $event_id): array {
    $payload['__correlation_id'] = $correlation_id;
    $payload['__sequence'] = $sequence;
    $payload['__event_id'] = $event_id;

    return $payload;
  }

  public static function unwrap(array $wrapped): self {
    $correlation_id = isset($wrapped['__correlation_id']) ? (string) $wrapped['__correlation_id'] : null;
    $sequence = isset($wrapped['__sequence']) ? (int) $wrapped['__sequence'] : null;
    $event_id = isset($wrapped['__event_id']) ? (string) $wrapped['__event_id'] : null;
    unset($wrapped['__correlation_id'], $wrapped['__sequence'], $wrapped['__event_id']);

    return new self($wrapped, $correlation_id, $sequence, $event_id);
  }

  /** Restore ambient correlation + stash this event as causation for dispatched commands. */
  public function restore_context(): void {
    if ($this->correlation_id !== null) {
      CorrelationContext::init($this->correlation_id);
    }
    if ($this->sequence !== null) {
      CorrelationContext::set_sequence($this->sequence);
    }
    if ($this->event_id !== null) {
      CorrelationContext::set_causation($this->event_id, 'integration_event');
    }
  }
}
