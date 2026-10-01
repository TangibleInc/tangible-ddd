<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * The fact a claimed `deliver` job carries (PdoJobStore::delivery_of()). The
 * drain hands it to IntegrationDelivery::deliver($eventClass, $envelope).
 * event_class is null when the outbox writer did not record it; the drain
 * then resolves it from integration_action / event_type.
 */
final class DeliveryJob {

  /** @param array<string, mixed> $envelope the wrapped integration payload (IntegrationEnvelope::wrap) */
  public function __construct(
    public readonly string $event_id,
    public readonly string $event_type,
    public readonly ?string $event_class,
    public readonly string $integration_action,
    public readonly array $envelope,
  ) {}
}
