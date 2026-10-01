<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * The fact a claimed `deliver` job carries (PdoJobStore::deliveryOf()). The
 * drain hands it to IntegrationDelivery::deliver($eventClass, $envelope).
 * eventClass is null when the outbox writer did not record it; the drain
 * then resolves it from integrationAction / eventType.
 */
final class DeliveryJob {

  /** @param array<string, mixed> $envelope the wrapped integration payload (IntegrationEnvelope::wrap) */
  public function __construct(
    public readonly string $eventId,
    public readonly string $eventType,
    public readonly ?string $eventClass,
    public readonly string $integrationAction,
    public readonly array $envelope,
  ) {}
}
