<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\OutboxRows;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\DeadLetter;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxRowIds;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IOutboxAdministration (and IOutboxRowIds) over the pdo outbox and DLQ
 * tables (register 3.4, C22, C23, O5, sfc-5).
 *
 * - retry(): refuses a LEASED row always (even forced) and any status other
 *   than `pending`/`dlq` unless forced; resets status, attempts, next attempt
 *   (now), lease, error and transport ref, and deletes the row's DLQ entries
 *   in the same transaction: a retried dead letter leaves the DLQ (sfc-5),
 *   so a later replay of it cannot reset an already re-queued row again.
 * - replay(): keeps event_id (C22). In one transaction it resets the
 *   original outbox row, or re-inserts it from the DLQ row (original
 *   event_id, due_at and class) when it was purged, and deletes the DLQ row.
 * - discard(): deletes the DLQ row only. purge(): deletes `accepted` rows
 *   accepted before the cutoff.
 *
 * Each mutating call is one transaction, or joins the repair command's
 * transaction when one is open. Unknown ids throw OutboxRowNotFound; storage
 * failures throw the driver exception.
 */
final class PdoOutboxAdministration implements IOutboxAdministration, IOutboxRowIds {

  private readonly string $outbox;
  private readonly string $dlq;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->outbox = $tables->table('ddd_outbox');
    $this->dlq = $tables->table('ddd_dlq');
    $this->clock = $clock ?? new SystemClock();
  }

  public function deadLetters(int $limit, ?string $after = null): array {
    $rows = $this->db->fetchAll(
      "SELECT * FROM `{$this->dlq}` WHERE id > ? ORDER BY id LIMIT ?",
      [$after === null ? 0 : (int) $after, max(0, $limit)]
    );
    return array_map(static fn (array $row) => new DeadLetter(
      (int) $row['id'],
      (string) $row['event_id'],
      (string) $row['error'],
      (int) $row['attempts'],
      Utc::fromDb((string) $row['dead_lettered_at']),
      OutboxRows::record($row),
    ), $rows);
  }

  public function retry(string $event_id, bool $force = false): void {
    $this->transactionally(function () use ($event_id, $force): void {
      $row = $this->db->fetchOne(
        "SELECT status, claim_token, lease_until FROM `{$this->outbox}` WHERE event_id = ? FOR UPDATE",
        [$event_id]
      ) ?? throw new OutboxRowNotFound("Outbox row $event_id not found");

      if ($row['claim_token'] !== null && Utc::fromDb((string) $row['lease_until']) > $this->clock->now()) {
        throw new OutboxAdministrationRefused("Outbox row $event_id is leased; retry refused");
      }
      if (!$force && !in_array($row['status'], ['pending', 'dlq'], true)) {
        throw new OutboxAdministrationRefused("Outbox row $event_id is {$row['status']}; retry needs force");
      }
      $this->reset($event_id);
      $this->db->execute("DELETE FROM `{$this->dlq}` WHERE event_id = ?", [$event_id]);
    });
  }

  public function replay(int $dlqId): void {
    $this->transactionally(function () use ($dlqId): void {
      $letter = $this->db->fetchOne("SELECT * FROM `{$this->dlq}` WHERE id = ? FOR UPDATE", [$dlqId])
        ?? throw new OutboxRowNotFound("Dead letter #$dlqId not found");

      $exists = $this->db->fetchOne("SELECT id FROM `{$this->outbox}` WHERE event_id = ? FOR UPDATE", [$letter['event_id']]);
      if ($exists === null) {
        $now = Utc::toDb($this->clock->now());
        $columns = array_combine(OutboxRows::SHARED, OutboxRows::sharedValues($letter)) + [
          'status' => 'pending',
          'next_attempt_at' => $now,
          'created_at' => $now,
        ];
        $this->db->execute(OutboxRows::insertSql($this->outbox, $columns), array_values($columns));
      } else {
        $this->reset((string) $letter['event_id']);
      }
      $this->db->execute("DELETE FROM `{$this->dlq}` WHERE id = ?", [$dlqId]);
    });
  }

  public function discard(int $dlqId): void {
    if ($this->db->execute("DELETE FROM `{$this->dlq}` WHERE id = ?", [$dlqId]) === 0) {
      throw new OutboxRowNotFound("Dead letter #$dlqId not found");
    }
  }

  public function purge(\DateTimeImmutable $olderThan): int {
    return $this->db->execute(
      "DELETE FROM `{$this->outbox}` WHERE status = 'accepted' AND accepted_at < ?",
      [Utc::toDb($olderThan)]
    );
  }

  public function stats(): array {
    $stats = ['pending' => 0, 'accepted' => 0, 'dlq' => 0, 'cancelled' => 0];
    foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS n FROM `{$this->outbox}` GROUP BY status") as $row) {
      $stats[(string) $row['status']] = (int) $row['n'];
    }
    $stats['dead_letters'] = (int) ($this->db->fetchOne("SELECT COUNT(*) AS n FROM `{$this->dlq}`")['n'] ?? 0);
    return $stats;
  }

  public function eventIdOf(int $outboxId): ?string {
    $id = $this->db->fetchOne("SELECT event_id FROM `{$this->outbox}` WHERE id = ?", [$outboxId])['event_id'] ?? null;
    return $id === null ? null : (string) $id;
  }

  private function reset(string $eventId): void {
    $this->db->execute(
      "UPDATE `{$this->outbox}` SET status = 'pending', attempts = 0, next_attempt_at = ?, last_error = NULL,
         claim_token = NULL, lease_until = NULL, transport_ref = NULL, accepted_at = NULL
       WHERE event_id = ?",
      [Utc::toDb($this->clock->now()), $eventId]
    );
  }

  private function transactionally(callable $work): void {
    if ($this->db->inTransaction()) {
      $work();
      return;
    }
    $this->db->begin();
    try {
      $work();
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }
}
