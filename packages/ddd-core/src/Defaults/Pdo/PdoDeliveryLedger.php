<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IDeliveryLedger on `{prefix}ddd_delivery_ledger` (register 3.5, 5.1,
 * CR-1): one row per (subscriber_id, event_id).
 *
 * - markFailed() sets attempts to the 1-based attempt that failed and
 *   stores last_error; it never touches exhausted_at.
 * - markDelivered() sets delivered_at and clears last_error; attempts stay.
 * - markExhausted() sets exhausted_at once (idempotent; the first marker is
 *   kept).
 *
 * Every write is a single upsert on the host connection, so it commits or
 * rolls back with the subscriber's transaction when one is open. Storage
 * failures throw the driver exception.
 */
final class PdoDeliveryLedger implements IDeliveryLedger {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_delivery_ledger');
    $this->clock = $clock ?? new SystemClock();
  }

  public function delivered(string $subscriberId, string $eventId): bool {
    return ($this->row($subscriberId, $eventId)['delivered_at'] ?? null) !== null;
  }

  public function markDelivered(string $subscriberId, string $eventId): void {
    $now = $this->now();
    $this->db->execute(
      "INSERT INTO `{$this->table}` (subscriber_id, event_id, attempts, delivered_at, updated_at) VALUES (?, ?, 0, ?, ?)
       ON DUPLICATE KEY UPDATE delivered_at = COALESCE(delivered_at, ?), last_error = NULL, updated_at = ?",
      [$subscriberId, $eventId, $now, $now, $now, $now]
    );
  }

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $now = $this->now();
    $this->db->execute(
      "INSERT INTO `{$this->table}` (subscriber_id, event_id, attempts, last_error, updated_at) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE attempts = ?, last_error = ?, delivered_at = NULL, updated_at = ?",
      [$subscriberId, $eventId, $attempt, $error, $now, $attempt, $error, $now]
    );
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return (int) ($this->row($subscriberId, $eventId)['attempts'] ?? 0);
  }

  public function lastError(string $subscriberId, string $eventId): ?string {
    $error = $this->row($subscriberId, $eventId)['last_error'] ?? null;
    return $error === null ? null : (string) $error;
  }

  public function markExhausted(string $subscriberId, string $eventId): void {
    $now = $this->now();
    $this->db->execute(
      "INSERT INTO `{$this->table}` (subscriber_id, event_id, attempts, exhausted_at, updated_at) VALUES (?, ?, 0, ?, ?)
       ON DUPLICATE KEY UPDATE exhausted_at = COALESCE(exhausted_at, ?), updated_at = ?",
      [$subscriberId, $eventId, $now, $now, $now, $now]
    );
  }

  public function exhausted(string $subscriberId, string $eventId): bool {
    return ($this->row($subscriberId, $eventId)['exhausted_at'] ?? null) !== null;
  }

  /** @return array<string, mixed>|null */
  private function row(string $subscriberId, string $eventId): ?array {
    return $this->db->fetchOne(
      "SELECT attempts, delivered_at, last_error, exhausted_at FROM `{$this->table}` WHERE subscriber_id = ? AND event_id = ?",
      [$subscriberId, $eventId]
    );
  }

  private function now(): string {
    return Utc::toDb($this->clock->now());
  }
}
