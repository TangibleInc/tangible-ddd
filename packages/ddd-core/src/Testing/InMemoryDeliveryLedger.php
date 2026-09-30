<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Delivery\IDeliveryLedger;

/** In-memory IDeliveryLedger. */
final class InMemoryDeliveryLedger implements IDeliveryLedger, InMemoryTransactional {

  /** @var array<string, array{delivered: bool, attempts: int, error: ?string, exhausted: bool}> */
  private array $rows = [];

  public function delivered(string $subscriberId, string $eventId): bool {
    return $this->rows[self::key($subscriberId, $eventId)]['delivered'] ?? false;
  }

  public function markDelivered(string $subscriberId, string $eventId): void {
    $row = $this->row($subscriberId, $eventId);
    $row['delivered'] = true;
    $row['error'] = null;
    $this->rows[self::key($subscriberId, $eventId)] = $row;
  }

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $row = $this->row($subscriberId, $eventId);
    $row['delivered'] = false;
    $row['attempts'] = $attempt;
    $row['error'] = $error;
    $this->rows[self::key($subscriberId, $eventId)] = $row;
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return $this->rows[self::key($subscriberId, $eventId)]['attempts'] ?? 0;
  }

  public function lastError(string $subscriberId, string $eventId): ?string {
    return $this->rows[self::key($subscriberId, $eventId)]['error'] ?? null;
  }

  public function markExhausted(string $subscriberId, string $eventId): void {
    $row = $this->row($subscriberId, $eventId);
    $row['exhausted'] = true;
    $this->rows[self::key($subscriberId, $eventId)] = $row;
  }

  public function exhausted(string $subscriberId, string $eventId): bool {
    return $this->rows[self::key($subscriberId, $eventId)]['exhausted'] ?? false;
  }

  public function snapshotState(): mixed {
    return $this->rows;
  }

  public function restoreState(mixed $state): void {
    $this->rows = $state;
  }

  /** @return array{delivered: bool, attempts: int, error: ?string, exhausted: bool} */
  private function row(string $subscriberId, string $eventId): array {
    return $this->rows[self::key($subscriberId, $eventId)]
      ?? ['delivered' => false, 'attempts' => 0, 'error' => null, 'exhausted' => false];
  }

  private static function key(string $subscriberId, string $eventId): string {
    return $subscriberId . "\0" . $eventId;
  }
}
