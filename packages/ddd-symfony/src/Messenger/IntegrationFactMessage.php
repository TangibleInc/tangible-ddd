<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

/**
 * One published fact on the `ddd_facts` Messenger transport (register 3.5,
 * E section 7). One message per fact, however many subscribers it has; the
 * delivery handler walks them through the core invoker and the ledger.
 *
 * `wrappedPayload` is exactly IntegrationEnvelope::wrap() output, so the
 * envelope keys (`__correlation_id`, `__sequence`, `__event_id`, R4) are the
 * same as on WordPress. `eventClass` is the fact's PHP class: delivery
 * hydrates it with from_payload() and matches marker subscriptions (D2).
 */
final class IntegrationFactMessage {

  /** @param array<string, mixed> $wrappedPayload */
  public function __construct(
    public readonly string $consumer,
    public readonly string $eventId,
    public readonly string $eventType,
    public readonly string $eventClass,
    public readonly string $integrationAction,
    public readonly array $wrappedPayload,
  ) {}
}
