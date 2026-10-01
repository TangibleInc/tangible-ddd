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

  /** @param array<string, mixed> $envelope */
  public function __construct(
    public readonly string $consumer,
    public readonly string $event_id,
    public readonly string $event_type,
    public readonly string $event_class,
    public readonly string $integration_action,
    public readonly array $envelope,
  ) {}
}
