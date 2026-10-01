<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\DeadLetter;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IOutboxAdministration over the sf outbox and DLQ tables (register 3.4).
 *
 * - retry(): refuses a leased row always and a non-`pending`/`dlq` row
 *   unless forced (O5); resets status, attempts, next attempt, lease, error,
 *   and deletes the row's DLQ entries in the same transaction (a retried
 *   dead letter leaves the DLQ, as a replayed one does).
 * - replay(): keeps event_id (C22). In one transaction it resets the
 *   original outbox row, or re-inserts it from the DLQ row when it was
 *   purged, and deletes the DLQ row.
 * - discard(): deletes the DLQ row only. purge(): deletes `accepted` rows
 *   accepted before the cutoff.
 * Unknown ids throw OutboxRowNotFound; storage failures the DBAL exception.
 */
final class DbalOutboxAdministration implements IOutboxAdministration {

  private readonly string $outbox;
  private readonly string $dlq;
  private readonly IClock $clock;

  public function __construct(private readonly Connection $connection, ?IClock $clock = null, string $tablePrefix = '') {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->outbox = $tables->table('ddd_outbox');
    $this->dlq = $tables->table('ddd_dlq');
    $this->clock = $clock ?? new SystemClock();
  }

  public function dead_letters(int $limit, ?string $after = null): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT * FROM {$this->dlq} WHERE id > ? ORDER BY id LIMIT ?",
      [$after === null ? 0 : (int) $after, max(0, $limit)],
      [ParameterType::INTEGER, ParameterType::INTEGER]
    );
    return array_map(static fn (array $row) => new DeadLetter(
      (int) $row['id'],
      (string) $row['event_id'],
      (string) $row['error'],
      (int) $row['attempts'],
      Time::from_db((string) $row['dead_lettered_at']),
      DbalPostgresOutboxStore::record_from_row($row),
    ), $rows);
  }

  public function retry(string $event_id, bool $force = false): void {
    $this->connection->transactional(function (Connection $conn) use ($event_id, $force): void {
      $row = $conn->fetchAssociative("SELECT status, claim_token, lease_until FROM {$this->outbox} WHERE event_id = ? FOR UPDATE", [$event_id])
        ?: throw new OutboxRowNotFound("Outbox row $event_id not found");

      if ($row['claim_token'] !== null && Time::from_db((string) $row['lease_until']) > $this->clock->now()) {
        throw new OutboxAdministrationRefused("Outbox row $event_id is leased; retry refused");
      }
      if (!$force && !in_array($row['status'], ['pending', 'dlq'], true)) {
        throw new OutboxAdministrationRefused("Outbox row $event_id is {$row['status']}; retry needs force");
      }
      $this->reset($conn, $event_id);
      // The row is back in the relay: its dead letter is no longer a dead
      // letter, and a later replay of it must not reset the row again.
      $conn->executeStatement("DELETE FROM {$this->dlq} WHERE event_id = ?", [$event_id]);
    });
  }

  public function replay(int $dlqId): void {
    $this->connection->transactional(function (Connection $conn) use ($dlqId): void {
      $letter = $conn->fetchAssociative("SELECT * FROM {$this->dlq} WHERE id = ? FOR UPDATE", [$dlqId], [ParameterType::INTEGER])
        ?: throw new OutboxRowNotFound("Dead letter #$dlqId not found");

      $exists = $conn->fetchOne("SELECT 1 FROM {$this->outbox} WHERE event_id = ?", [$letter['event_id']]);
      if ($exists === false) {
        $now = Time::to_db($this->clock->now());
        $conn->executeStatement(
          "INSERT INTO {$this->outbox} (event_id, event_type, event_class, integration_action, correlation_id, sequence, command_id,
              payload, payload_signature, signature_json, is_unique, max_attempts, due_at, next_attempt_at, blog_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          [
            $letter['event_id'], $letter['event_type'], $letter['event_class'], $letter['integration_action'], $letter['correlation_id'],
            $letter['sequence'], $letter['command_id'], $letter['payload'], $letter['payload_signature'], $letter['signature_json'],
            $letter['is_unique'], $letter['max_attempts'], $letter['due_at'], $now, $letter['blog_id'],
          ],
          [10 => ParameterType::BOOLEAN]
        );
      } else {
        $this->reset($conn, (string) $letter['event_id']);
      }
      $conn->executeStatement("DELETE FROM {$this->dlq} WHERE id = ?", [$dlqId], [ParameterType::INTEGER]);
    });
  }

  public function discard(int $dlqId): void {
    $n = $this->connection->executeStatement("DELETE FROM {$this->dlq} WHERE id = ?", [$dlqId], [ParameterType::INTEGER]);
    if ($n === 0) {
      throw new OutboxRowNotFound("Dead letter #$dlqId not found");
    }
  }

  public function purge(\DateTimeImmutable $olderThan): int {
    return (int) $this->connection->executeStatement(
      "DELETE FROM {$this->outbox} WHERE status = 'accepted' AND accepted_at < ?",
      [Time::to_db($olderThan)]
    );
  }

  public function stats(): array {
    $stats = ['pending' => 0, 'accepted' => 0, 'dlq' => 0, 'cancelled' => 0];
    foreach ($this->connection->fetchAllKeyValue("SELECT status, count(*) FROM {$this->outbox} GROUP BY status") as $status => $n) {
      $stats[(string) $status] = (int) $n;
    }
    $stats['dead_letters'] = (int) $this->connection->fetchOne("SELECT count(*) FROM {$this->dlq}");
    return $stats;
  }

  private function reset(Connection $conn, string $eventId): void {
    $conn->executeStatement(
      "UPDATE {$this->outbox} SET status = 'pending', attempts = 0, next_attempt_at = ?, last_error = NULL,
         claim_token = NULL, lease_until = NULL, transport_ref = NULL, accepted_at = NULL
       WHERE event_id = ?",
      [Time::to_db($this->clock->now()), $eventId]
    );
  }
}
