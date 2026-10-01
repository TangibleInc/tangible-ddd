<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\NestedTransactionRejected;
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
 * - claim_due(): ONE autocommit statement leasing due rows (`due_at <= now`,
 *   `next_attempt_at` passed, lease free or expired) with FOR UPDATE SKIP
 *   LOCKED, each with its own claim token. Refuses to join an open
 *   transaction (NestedTransactionRejected), as the outbox claim does.
 * - complete() deletes the row; retry_later() counts an attempt, records the
 *   error, sets next_attempt_at and clears the lease. Both are fenced on
 *   (idempotency_key, claim_token): false means the lease was lost.
 *
 * Wave 5 (AW2, schema 011): the `fact` column keeps WakeupIntent::$fact, so
 * schedule() stores it and claim_due() / find() return it. This class does
 * not declare ICarriesFacts: the runner parks a contended fact resume only
 * on DbalParkingScheduler, which the bundle wires (a scheduler built
 * directly keeps the wave-3 delivery retry).
 */
class DbalWakeupScheduler implements IWakeupScheduler {

  private readonly string $table;

  public function __construct(
    private readonly Connection $connection,
    string $tablePrefix = '',
    private readonly ?IRelayWakeup $wakeup = null,
  ) {
    $this->table = TableNames::of($tablePrefix)->table('ddd_wakeups');
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    // The fact column (schema 011) is named only for an intent that carries
    // a fact, so a host without it keeps scheduling every other intent.
    $columns = 'idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at';
    $params = [$i->key, $i->kind->value, $i->consumer, $i->process_id, $i->step_index, $i->expected_status, Time::to_db($i->due_at)];
    $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
      $i->process_id === null ? ParameterType::NULL : ParameterType::INTEGER,
      $i->step_index === null ? ParameterType::NULL : ParameterType::INTEGER,
      ParameterType::STRING, ParameterType::STRING];
    if ($i->fact !== null) {
      $columns .= ', fact';
      $params[] = self::fact_to_db($i);
      $types[] = ParameterType::STRING;
    }
    $inserted = $this->connection->executeStatement(
      "INSERT INTO {$this->table} ($columns) VALUES (" . implode(', ', array_fill(0, count($params), '?')) . ')
       ON CONFLICT (idempotency_key) DO NOTHING',
      $params,
      $types,
    );
    if ($inserted > 0) {
      $this->wakeup?->poke($i->consumer);
    }
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    $this->connection->executeStatement("DELETE FROM {$this->table} WHERE idempotency_key = ?", [$idempotencyKey]);
  }

  public function claim_due(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    if ($this->connection->isTransactionActive()) {
      throw new NestedTransactionRejected('DbalWakeupScheduler::claim_due() must run outside an open transaction (it commits its own lease).');
    }
    if ($limit <= 0) {
      return [];
    }
    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");
    $nowDb = Time::to_db($now);

    $rows = $this->connection->fetchAllAssociative(
      "WITH picked AS (
         SELECT id FROM {$this->table}
         WHERE due_at <= :now
           AND exhausted_at IS NULL
           AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
           AND (claim_token IS NULL OR lease_until <= :now)
         ORDER BY due_at, id
         LIMIT :limit
         FOR UPDATE SKIP LOCKED
       )
       UPDATE {$this->table} AS w SET claim_token = gen_random_uuid()::text, lease_until = :lease
       FROM picked WHERE w.id = picked.id
       RETURNING w.*",
      ['now' => $nowDb, 'lease' => Time::to_db($leaseUntil), 'limit' => $limit],
      ['limit' => ParameterType::INTEGER]
    );
    usort($rows, static fn (array $a, array $b) => [Time::from_db((string) $a['due_at']), (int) $a['id']] <=> [Time::from_db((string) $b['due_at']), (int) $b['id']]);

    return array_map(static fn (array $r) => new ClaimedWakeup(
      self::intent_from_row($r), (string) $r['claim_token'], $leaseUntil, (int) $r['attempts']
    ), $rows);
  }

  public function complete(ClaimedWakeup $w): bool {
    return $this->connection->executeStatement(
      "DELETE FROM {$this->table} WHERE idempotency_key = ? AND claim_token = ?",
      [$w->intent->key, $w->token]
    ) > 0;
  }

  public function retry_later(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->connection->executeStatement(
      "UPDATE {$this->table}
          SET attempts = attempts + 1, last_error = ?, next_attempt_at = ?, claim_token = NULL, lease_until = NULL
        WHERE idempotency_key = ? AND claim_token = ?",
      [$error, Time::to_db($nextAt), $w->intent->key, $w->token]
    ) > 0;
  }

  // ── sf additions (operator layer `wakeup`, 5.1; not on the port) ─────────

  /**
   * The wake budget ran out, or the wake failed for a non-retryable reason:
   * count the attempt, keep the row for the operator and never claim it
   * again (it no longer counts as a live intent for the stranded scan).
   * Fenced on the claim token like complete().
   */
  public function exhaust(ClaimedWakeup $w, string $error, ?\DateTimeImmutable $at = null): bool {
    return $this->connection->executeStatement(
      "UPDATE {$this->table}
          SET attempts = attempts + 1, last_error = ?, exhausted_at = ?, claim_token = NULL, lease_until = NULL
        WHERE idempotency_key = ? AND claim_token = ?",
      [$error, Time::to_db($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), $w->intent->key, $w->token]
    ) > 0;
  }

  /**
   * Exhausted intents, oldest first, for `ddd:ops:stranded`.
   *
   * @return list<array{intent: WakeupIntent, attempts: int, last_error: ?string, exhausted_at: \DateTimeImmutable}>
   */
  public function exhausted(int $limit = 100): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT * FROM {$this->table} WHERE exhausted_at IS NOT NULL ORDER BY exhausted_at, id LIMIT ?",
      [max(0, $limit)],
      [ParameterType::INTEGER]
    );
    return array_map(static fn (array $r) => [
      'intent' => self::intent_from_row($r),
      'attempts' => (int) $r['attempts'],
      'last_error' => $r['last_error'] === null ? null : (string) $r['last_error'],
      'exhausted_at' => Time::from_db((string) $r['exhausted_at']),
    ], $rows);
  }

  /**
   * Operator repair: put an exhausted (or stuck) intent back in line, due
   * at $dueAt with a fresh budget. Returns false for an unknown key.
   */
  public function rearm(string $idempotencyKey, \DateTimeImmutable $dueAt): bool {
    return $this->connection->executeStatement(
      "UPDATE {$this->table}
          SET exhausted_at = NULL, attempts = 0, next_attempt_at = NULL, claim_token = NULL, lease_until = NULL, due_at = ?
        WHERE idempotency_key = ?",
      [Time::to_db($dueAt), $idempotencyKey]
    ) > 0;
  }

  /** The stored intent with this key (its fact included), or null. Pending, leased or exhausted alike. */
  public function find(string $idempotencyKey): ?WakeupIntent {
    $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE idempotency_key = ?", [$idempotencyKey]);
    return $row === false ? null : self::intent_from_row($row);
  }

  /** @param array<string, mixed> $r */
  public static function intent_from_row(array $r): WakeupIntent {
    return new WakeupIntent(
      WakeKind::from((string) $r['kind']),
      (string) $r['consumer'],
      $r['process_id'] === null ? null : (int) $r['process_id'],
      $r['step_index'] === null ? null : (int) $r['step_index'],
      $r['expected_status'] === null ? null : (string) $r['expected_status'],
      Time::from_db((string) $r['due_at']),
      (string) $r['idempotency_key'],
      self::fact_from_db($r['fact'] ?? null, (string) $r['idempotency_key']),
    );
  }

  private static function fact_to_db(WakeupIntent $i): string {
    try {
      return json_encode($i->fact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    } catch (\JsonException $e) {
      throw new \RuntimeException("The fact of wakeup {$i->key} does not encode as JSON: " . $e->getMessage(), 0, $e);
    }
  }

  /** @return array{class: string, payload: array<string, mixed>, event_id: string}|null */
  private static function fact_from_db(mixed $value, string $key): ?array {
    if ($value === null) {
      return null;
    }
    try {
      $fact = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new \RuntimeException("The fact of wakeup $key does not decode: " . $e->getMessage(), 0, $e);
    }
    if (!is_array($fact)) {
      throw new \RuntimeException("The fact of wakeup $key is not a JSON object.");
    }
    return $fact;
  }

  private function assertInTransaction(string $op): void {
    if (!$this->connection->isTransactionActive()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction (register 3.6).");
    }
  }
}
