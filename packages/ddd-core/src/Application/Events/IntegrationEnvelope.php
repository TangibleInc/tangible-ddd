<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Application\Correlation\TraceContext;

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

  /**
   * The journey as a value (0.3): correlation + position, NO cause — the
   * envelope never carries causes (the raiser edge is at rest in
   * outbox.command_id). Drains derive: trace_context()->for_fact($event_id).
   * Null when the bag had no journey keys (a bare hook call — nothing to scope).
   */
  public function trace_context(): ?TraceContext {
    if ($this->correlation_id === null) {
      return null;
    }

    return new TraceContext(
      $this->correlation_id,
      null,
      $this->sequence ?? 0,
    );
  }
}
