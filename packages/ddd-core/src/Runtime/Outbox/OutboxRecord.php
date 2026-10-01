<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * One fact as the outbox stores it (register 3.4). Property names follow the
 * persisted column names (R4).
 *
 * `due_at` is ABSOLUTE and UTC (bug 3): it is computed once at append from
 * IClock + delay() and never re-derived; the transport never adds a relative
 * delay on top.
 */
final class OutboxRecord {

  public readonly \DateTimeImmutable $due_at;

  /**
   * @param array<string, mixed> $payload           the integration payload (unwrapped)
   * @param array<string, mixed>|null $payload_signature is_unique dedup signature
   * @param class-string|null $event_class the PHP fact class, filled by OutboxIntegrationEventBus
   *   (CR-PC-2, wave 4); null for hand-built or legacy records. Stores may persist it so the
   *   delivery side can hydrate the fact and match marker subscriptions (D2).
   */
  public function __construct(
    public readonly string $event_id,
    public readonly string $event_type,
    public readonly string $integration_action,
    public readonly ?string $correlation_id,
    public readonly ?int $sequence,
    public readonly ?string $command_id,
    public readonly array $payload,
    \DateTimeImmutable $due_at,
    public readonly bool $is_unique = false,
    public readonly ?array $payload_signature = null,
    public readonly int $max_attempts = 5,
    public readonly ?int $blog_id = null,
    public readonly ?string $event_class = null,
  ) {
    $this->due_at = $due_at->setTimezone(new \DateTimeZone('UTC'));
  }
}
