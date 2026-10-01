<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

/**
 * One published fact on the `ddd_facts` Messenger transport (register 3.5,
 * E section 7). One message per fact, however many subscribers it has; the
 * delivery handler walks them through the core invoker and the ledger.
 *
 * `envelope` is exactly IntegrationEnvelope::wrap() output, so the
 * envelope keys (`__correlation_id`, `__sequence`, `__event_id`, R4) are the
 * same as on WordPress. `event_class` is the fact's PHP class: delivery
 * hydrates it with from_payload() and matches marker subscriptions (D2).
 */
final class IntegrationFactMessage {

  /**
   * @param string $consumer the consumer that raised the fact (its outbox)
   * @param array<string, mixed> $envelope
   * @param ?string $to the consumer whose subscribers this copy is for; null = the raiser
   *   (wave 5: a fact raised by one consumer is routed to every other consumer that subscribes to it)
   */
  public function __construct(
    public readonly string $consumer,
    public readonly string $event_id,
    public readonly string $event_type,
    public readonly string $event_class,
    public readonly string $integration_action,
    public readonly array $envelope,
    private readonly ?string $to = null,
  ) {}

  /** The consumer this copy is delivered to (a message serialized before wave 5 has none: the raiser). */
  public function recipient(): string {
    return isset($this->to) ? $this->to : $this->consumer;
  }
}
