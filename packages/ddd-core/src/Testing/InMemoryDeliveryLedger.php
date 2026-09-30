<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Delivery\IDeliveryLedger;

/** In-memory IDeliveryLedger. */
final class InMemoryDeliveryLedger implements IDeliveryLedger, InMemoryTransactional {

  /** @var array<string, array{delivered: bool, attempts: int, error: ?string}> */
  private array $rows = [];

  public function delivered(string $subscriberId, string $eventId): bool {
    return $this->rows[self::key($subscriberId, $eventId)]['delivered'] ?? false;
  }

  public function markDelivered(string $subscriberId, string $eventId): void {
    $k = self::key($subscriberId, $eventId);
    $this->rows[$k] = ['delivered' => true, 'attempts' => $this->rows[$k]['attempts'] ?? 0, 'error' => null];
  }

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $this->rows[self::key($subscriberId, $eventId)] = ['delivered' => false, 'attempts' => $attempt, 'error' => $error];
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return $this->rows[self::key($subscriberId, $eventId)]['attempts'] ?? 0;
  }

  public function lastError(string $subscriberId, string $eventId): ?string {
    return $this->rows[self::key($subscriberId, $eventId)]['error'] ?? null;
  }

  public function snapshotState(): mixed {
    return $this->rows;
  }

  public function restoreState(mixed $state): void {
    $this->rows = $state;
  }

  private static function key(string $subscriberId, string $eventId): string {
    return $subscriberId . "\0" . $eventId;
  }
}
