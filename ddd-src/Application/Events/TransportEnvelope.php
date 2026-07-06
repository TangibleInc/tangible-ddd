<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Application\Correlation\CorrelationContext;

/**
 * The journey's wire form. OutboxProcessor smears __-keys into the payload bag
 * for transport; this is the single place they are separated back out.
 * Used by the WP integration_action() helper, the listener ceremony, and the
 * process runner's wake path.
 */
final class TransportEnvelope {

  private function __construct(
    public readonly array $payload,
    public readonly ?string $correlation_id,
    public readonly ?int $sequence,
    public readonly ?string $event_id,
  ) {}

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
