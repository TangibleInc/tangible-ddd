<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * IDeliveryLedger on `{prefix}ddd_delivery_ledger` (register 3.5, 5.1, CR-1):
 * one row per (subscriber_id, event_id) with attempts, delivered_at,
 * last_error and the terminal exhausted_at marker.
 *
 * Every write is a single upsert in autocommit (or the ambient transaction,
 * if the caller has one). The core invoker writes mark_delivered() AFTER the
 * subscriber's own command committed, so a crash in between re-runs that
 * subscriber: listeners stay idempotent (at-least-once per subscriber).
 *
 * Errors: storage failures throw the DBAL exception; IntegrationDelivery
 * propagates them and the fact is retried as a whole.
 */
final class DbalDeliveryLedger implements IDeliveryLedger {

  private readonly string $table;

  public function __construct(private readonly Connection $connection, string $tablePrefix = '') {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_delivery_ledger');
  }

  public function delivered(string $subscriberId, string $eventId): bool {
    return $this->column('delivered_at IS NOT NULL', $subscriberId, $eventId) === true;
  }

  public function mark_delivered(string $subscriberId, string $eventId): void {
    $this->connection->executeStatement(
      "INSERT INTO {$this->table} (subscriber_id, event_id, delivered_at, updated_at) VALUES (?, ?, now(), now())
       ON CONFLICT (subscriber_id, event_id) DO UPDATE
         SET delivered_at = COALESCE({$this->table}.delivered_at, now()), updated_at = now()",
      [$subscriberId, $eventId]
    );
  }

  public function mark_failed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $this->connection->executeStatement(
      "INSERT INTO {$this->table} (subscriber_id, event_id, attempts, last_error, updated_at) VALUES (?, ?, ?, ?, now())
       ON CONFLICT (subscriber_id, event_id) DO UPDATE
         SET attempts = EXCLUDED.attempts, last_error = EXCLUDED.last_error, delivered_at = NULL, updated_at = now()",
      [$subscriberId, $eventId, $attempt, $error],
      [2 => ParameterType::INTEGER]
    );
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return (int) ($this->column('attempts', $subscriberId, $eventId) ?? 0);
  }

  public function last_error(string $subscriberId, string $eventId): ?string {
    $error = $this->column('last_error', $subscriberId, $eventId);
    return is_string($error) ? $error : null;
  }

  public function mark_exhausted(string $subscriberId, string $eventId): void {
    $this->connection->executeStatement(
      "INSERT INTO {$this->table} (subscriber_id, event_id, exhausted_at, updated_at) VALUES (?, ?, now(), now())
       ON CONFLICT (subscriber_id, event_id) DO UPDATE
         SET exhausted_at = COALESCE({$this->table}.exhausted_at, now()), updated_at = now()",
      [$subscriberId, $eventId]
    );
  }

  public function exhausted(string $subscriberId, string $eventId): bool {
    return $this->column('exhausted_at IS NOT NULL', $subscriberId, $eventId) === true;
  }

  private function column(string $expression, string $subscriberId, string $eventId): mixed {
    $value = $this->connection->fetchOne(
      "SELECT $expression FROM {$this->table} WHERE subscriber_id = ? AND event_id = ?",
      [$subscriberId, $eventId]
    );
    return $value === false ? null : $value;
  }
}
