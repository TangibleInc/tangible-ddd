<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;

/**
 * IWakeupScheduler on the `ddd_wakeups` intent table (register 3.6, 5.3, D7).
 * The intent row is the source of truth; WakeupRelay projects due rows to
 * the `ddd_wakeups` Messenger transport, and the wake handler completes them.
 *
 * - schedule(): an INSERT ... ON CONFLICT (idempotency_key) DO NOTHING on
 *   the process store's connection, which must be inside a transaction
 *   (WakeupOutsideTransaction otherwise): the intent and the process state
 *   change commit together or not at all (C8, C9). With an IRelayWakeup it
 *   pokes it in the same transaction (a transactional NOTIFY is delivered on
 *   commit only, D14).
 * - cancel(): DELETE by key, same transaction rule.
 * - claimDue(): ONE autocommit statement leasing due rows (`due_at <= now`,
 *   `next_attempt_at` passed, lease free or expired) with FOR UPDATE SKIP
 *   LOCKED, each with its own claim token. Refuses to join an open
 *   transaction (NestedTransactionRejected), as the outbox claim does.
 * - complete() deletes the row; retryLater() counts an attempt, records the
 *   error, sets next_attempt_at and clears the lease. Both are fenced on
 *   (idempotency_key, claim_token): false means the lease was lost.
 */
final class DbalWakeupScheduler implements IWakeupScheduler {

  private readonly string $table;

  public function __construct(
    private readonly Connection $connection,
    string $tablePrefix = '',
    private readonly ?IRelayWakeup $wakeup = null,
  ) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_wakeups');
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    $inserted = $this->connection->executeStatement(
      "INSERT INTO {$this->table} (idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT (idempotency_key) DO NOTHING",
      [$i->idempotencyKey, $i->kind->value, $i->consumer, $i->processId, $i->stepIndex, $i->expectedStatus, Time::toDb($i->dueAt)],
      [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
        $i->processId === null ? ParameterType::NULL : ParameterType::INTEGER,
        $i->stepIndex === null ? ParameterType::NULL : ParameterType::INTEGER,
        ParameterType::STRING, ParameterType::STRING]
    );
    if ($inserted > 0) {
      $this->wakeup?->poke($i->consumer);
    }
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    $this->connection->executeStatement("DELETE FROM {$this->table} WHERE idempotency_key = ?", [$idempotencyKey]);
  }

  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    if ($this->connection->isTransactionActive()) {
      throw new NestedTransactionRejected('DbalWakeupScheduler::claimDue() must run outside an open transaction (it commits its own lease).');
    }
    if ($limit <= 0) {
      return [];
    }
    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");
    $nowDb = Time::toDb($now);

    $rows = $this->connection->fetchAllAssociative(
      "WITH picked AS (
         SELECT id FROM {$this->table}
         WHERE due_at <= :now
           AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
           AND (claim_token IS NULL OR lease_until <= :now)
         ORDER BY due_at, id
         LIMIT :limit
         FOR UPDATE SKIP LOCKED
       )
       UPDATE {$this->table} AS w SET claim_token = gen_random_uuid()::text, lease_until = :lease
       FROM picked WHERE w.id = picked.id
       RETURNING w.*",
      ['now' => $nowDb, 'lease' => Time::toDb($leaseUntil), 'limit' => $limit],
      ['limit' => ParameterType::INTEGER]
    );
    usort($rows, static fn (array $a, array $b) => [Time::fromDb((string) $a['due_at']), (int) $a['id']] <=> [Time::fromDb((string) $b['due_at']), (int) $b['id']]);

    return array_map(static fn (array $r) => new ClaimedWakeup(
      self::intentOf($r), (string) $r['claim_token'], $leaseUntil, (int) $r['attempts']
    ), $rows);
  }

  public function complete(ClaimedWakeup $w): bool {
    return $this->connection->executeStatement(
      "DELETE FROM {$this->table} WHERE idempotency_key = ? AND claim_token = ?",
      [$w->intent->idempotencyKey, $w->claimToken]
    ) > 0;
  }

  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->connection->executeStatement(
      "UPDATE {$this->table}
          SET attempts = attempts + 1, last_error = ?, next_attempt_at = ?, claim_token = NULL, lease_until = NULL
        WHERE idempotency_key = ? AND claim_token = ?",
      [$error, Time::toDb($nextAt), $w->intent->idempotencyKey, $w->claimToken]
    ) > 0;
  }

  /** @param array<string, mixed> $r */
  public static function intentOf(array $r): WakeupIntent {
    return new WakeupIntent(
      WakeKind::from((string) $r['kind']),
      (string) $r['consumer'],
      $r['process_id'] === null ? null : (int) $r['process_id'],
      $r['step_index'] === null ? null : (int) $r['step_index'],
      $r['expected_status'] === null ? null : (string) $r['expected_status'],
      Time::fromDb((string) $r['due_at']),
      (string) $r['idempotency_key'],
    );
  }

  private function assertInTransaction(string $op): void {
    if (!$this->connection->isTransactionActive()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction (register 3.6).");
    }
  }
}
