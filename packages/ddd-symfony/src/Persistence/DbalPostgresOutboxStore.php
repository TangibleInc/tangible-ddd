<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * IOutboxStore on Postgres 16 through DBAL 4 (register 3.4, E F4, F13).
 *
 * - append(): an INSERT on the ambient connection, so it commits or rolls
 *   back with the command's DbalTransactionBoundary. Any failure (duplicate
 *   event_id included) throws OutboxWriteFailed. is_unique cancels older
 *   UNLEASED `pending` rows with the same event type and signature first.
 * - claim(): ONE autocommit statement, the fenced claim:
 *     UPDATE ... SET claim_token, lease_until
 *     WHERE id IN (SELECT id ... FOR UPDATE SKIP LOCKED LIMIT n) RETURNING *
 *   Rows are due (`due_at <= now`, `next_attempt_at <= now`), lease-free and
 *   not paused. Re-claiming a row whose lease EXPIRED counts one attempt
 *   (its holder died without an outcome); once attempts reach max_attempts
 *   the row is dead-lettered at claim instead of being handed out again. With a DbalRelayPauseStore the pause check is part of the
 *   statement; any other IRelayPauseStore is applied afterwards and the
 *   paused claims are handed back. Refuses to run inside an open transaction
 *   (NestedTransactionRejected): it must not silently join, and commit with,
 *   someone else's unit of work.
 * - accept() / retry_later() / dead_letter(): fenced on (event_id, claim_token)
 *   and status `pending`; 0 rows = lease lost → false, nothing thrown. An
 *   expired lease nobody re-claimed still matches.
 *
 * sf addition (not on the port): the fact's PHP class. append_fact() stores
 * it; event_class_of() returns it to the Messenger transport, which needs it to
 * hydrate the fact and match marker subscriptions (D2). See CR sf-1 in
 * docs/extraction/wave2-symfony-adapters-change-requests.md. with_event_class()
 * scopes a class for append() calls that come from the core bus.
 *
 * D14: with an IRelayWakeup, every append pokes it for $wakeupConsumer on
 * the same connection (a transactional NOTIFY, delivered at COMMIT).
 */
final class DbalPostgresOutboxStore implements IOutboxStore, IReportsClaimDeadLetters {

  // LEASE_EXPIRED_ERROR is IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR (the
  // same text sf shipped in wave 2), so self::LEASE_EXPIRED_ERROR still resolves.

  private readonly string $outbox;
  private readonly string $dlq;
  private readonly LoggerInterface $logger;

  /** @var array<string, ?string> event_id → class, from the latest claims */
  private array $claimedClasses = [];

  /** @var list<array{0: Claim, 1: string}> dead-lettered by claim() and not yet taken */
  private array $claim_dead_letters = [];

  private ?string $scopedClass = null;

  public function __construct(
    private readonly Connection $connection,
    private readonly ?IRelayPauseStore $pauses = null,
    string $tablePrefix = '',
    ?LoggerInterface $logger = null,
    private readonly ?IRelayWakeup $wakeup = null,
    private readonly string $wakeupConsumer = '',
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->outbox = $tables->table('ddd_outbox');
    $this->dlq = $tables->table('ddd_dlq');
    $this->logger = $logger ?? new NullLogger();
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function append(OutboxRecord $r): void {
    // Forward compatible with an OutboxRecord that carries the class (CR sf-1);
    // until then the bus decorator scopes the class with with_event_class().
    $class = property_exists($r, 'event_class') ? $r->event_class : null;
    $this->append_fact($r, is_string($class) ? $class : $this->scopedClass);
  }

  /**
   * Run $work with $eventClass as the class of every record append()ed
   * inside it that does not carry its own (CR sf-1 bridge for the core
   * OutboxIntegrationEventBus, whose OutboxRecord has no class yet).
   *
   * @template T
   * @param callable():T $work
   * @return T
   */
  public function with_event_class(string $eventClass, callable $work): mixed {
    $previous = $this->scopedClass;
    $this->scopedClass = $eventClass;
    try {
      return $work();
    } finally {
      $this->scopedClass = $previous;
    }
  }

  /** append() plus the fact's PHP class. @throws OutboxWriteFailed */
  public function append_fact(OutboxRecord $r, ?string $eventClass): void {
    try {
      $payload = self::json($r->payload);
      $signatureJson = $r->payload_signature === null ? null : self::json(self::canonical($r->payload_signature));
      $signature = $signatureJson === null ? null : hash('sha256', $signatureJson);

      if ($r->is_unique && $signature !== null) {
        $this->connection->executeStatement(
          "UPDATE {$this->outbox} SET status = 'cancelled'
           WHERE status = 'pending' AND claim_token IS NULL AND event_type = ? AND payload_signature = ?",
          [$r->event_type, $signature]
        );
      }

      $due = Time::to_db($r->due_at);
      $this->connection->insert($this->outbox, [
        'event_id' => $r->event_id,
        'event_type' => $r->event_type,
        'event_class' => $eventClass,
        'integration_action' => $r->integration_action,
        'correlation_id' => $r->correlation_id,
        'sequence' => $r->sequence,
        'command_id' => $r->command_id,
        'payload' => $payload,
        'payload_signature' => $signature,
        'signature_json' => $signatureJson,
        'is_unique' => $r->is_unique,
        'max_attempts' => $r->max_attempts,
        'due_at' => $due,
        'next_attempt_at' => $due,
        'blog_id' => $r->blog_id,
      ], [
        'sequence' => ParameterType::INTEGER,
        'is_unique' => ParameterType::BOOLEAN,
        'max_attempts' => ParameterType::INTEGER,
        'blog_id' => ParameterType::INTEGER,
      ]);
    } catch (OutboxWriteFailed $e) {
      throw $e;
    } catch (\Throwable $e) {
      throw new OutboxWriteFailed("Outbox append of {$r->event_id} failed: " . $e->getMessage(), 0, $e);
    }
    // D14: in the command's transaction a NOTIFY is delivered at COMMIT only.
    $this->wakeup?->poke($this->wakeupConsumer);
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    if ($this->connection->isTransactionActive()) {
      throw new NestedTransactionRejected('IOutboxStore::claim() must run outside any open transaction.');
    }
    if ($limit <= 0) {
      return [];
    }

    $token = bin2hex(random_bytes(16));
    $nowDb = Time::to_db($now);
    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");

    $pauseSql = '';
    $params = ['token' => $token, 'lease' => Time::to_db($leaseUntil), 'now' => $nowDb, 'limit' => $limit];
    $types = ['limit' => ParameterType::INTEGER];
    if ($this->pauses instanceof DbalRelayPauseStore && $this->pauses->connection() === $this->connection) {
      $patterns = $this->pauses->patterns($now);
      if ($patterns !== []) {
        $pauseSql = 'AND NOT (event_type ~ ANY(ARRAY[:patterns]::text[]))';
        $params['patterns'] = $patterns;
        $types['patterns'] = ArrayParameterType::STRING;
      }
    }

    // The fenced claim. The locked id set comes from a CTE rather than
    // `WHERE id IN (...)` only so the statement can see whether each row still
    // held an (expired) lease: that re-claim counts as an attempt, because the
    // previous holder died without writing an outcome (fatal, OOM, SIGKILL).
    $params['expired'] = self::LEASE_EXPIRED_ERROR;
    $rows = $this->connection->fetchAllAssociative(
      "WITH picked AS (
         SELECT id, (claim_token IS NOT NULL) AS reclaimed FROM {$this->outbox}
         WHERE status = 'pending'
           AND due_at <= :now
           AND next_attempt_at <= :now
           AND (claim_token IS NULL OR lease_until <= :now)
           $pauseSql
         ORDER BY due_at, id
         LIMIT :limit
         FOR UPDATE SKIP LOCKED
       )
       UPDATE {$this->outbox} AS o SET
         claim_token = :token,
         lease_until = :lease,
         attempts = o.attempts + CASE WHEN picked.reclaimed THEN 1 ELSE 0 END,
         last_error = CASE WHEN picked.reclaimed THEN :expired ELSE o.last_error END
       FROM picked
       WHERE o.id = picked.id
       RETURNING o.*",
      $params,
      $types
    );

    usort($rows, static fn (array $a, array $b) => [$a['due_at'], (int) $a['id']] <=> [$b['due_at'], (int) $b['id']]);

    $claims = [];
    foreach ($rows as $row) {
      $claim = new Claim((string) $row['event_id'], $token, $leaseUntil, self::record_from_row($row), (int) $row['attempts']);
      if ($claim->attempts >= $claim->record->max_attempts) {
        // Its lease expired max_attempts times: stop re-claiming it forever.
        $error = sprintf('%s %d times; dead-lettered at claim', self::LEASE_EXPIRED_ERROR, $claim->attempts);
        if ($this->moveToDlq($claim, $error, 0)) {
          $this->claim_dead_letters[] = [$claim, $error];
        }
        $this->logger->error("[ddd outbox] {$claim->event_id} dead-lettered at claim: its lease expired {$claim->attempts} times without an outcome");
        continue;
      }
      if ($this->pauses !== null && !$this->pauses instanceof DbalRelayPauseStore
        && $this->pauses->is_paused($claim->record->event_type, $now)) {
        $this->unclaim($claim);
        continue;
      }
      $this->claimedClasses[$claim->event_id] = $row['event_class'] === null ? null : (string) $row['event_class'];
      $claims[] = $claim;
    }

    // Bound the side map to what one relay step can use.
    if (count($this->claimedClasses) > 4 * max($limit, 64)) {
      $this->claimedClasses = array_slice($this->claimedClasses, -$limit, null, true);
    }

    return $claims;
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    return $this->fenced(
      "UPDATE {$this->outbox} SET status = 'accepted', transport_ref = ?, accepted_at = now(), claim_token = NULL, lease_until = NULL
       WHERE event_id = ? AND claim_token = ? AND status = 'pending'",
      [$transportRef, $c->event_id, $c->token],
      $c,
      'accept'
    );
  }

  public function retry_later(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->fenced(
      "UPDATE {$this->outbox} SET attempts = attempts + 1, next_attempt_at = ?, last_error = ?, claim_token = NULL, lease_until = NULL
       WHERE event_id = ? AND claim_token = ? AND status = 'pending'",
      [Time::to_db($nextAt), $error, $c->event_id, $c->token],
      $c,
      'retry_later'
    );
  }

  public function dead_letter(Claim $c, string $error): bool {
    return $this->moveToDlq($c, $error, 1);
  }

  /** @param int $countAttempt 1 when this outcome is itself a failed attempt, 0 when claim() already counted it */
  private function moveToDlq(Claim $c, string $error, int $countAttempt): bool {
    return $this->connection->transactional(function (Connection $conn) use ($c, $error, $countAttempt): bool {
      $row = $conn->fetchAssociative(
        "UPDATE {$this->outbox} SET status = 'dlq', attempts = attempts + ?, last_error = ?, claim_token = NULL, lease_until = NULL
         WHERE event_id = ? AND claim_token = ? AND status = 'pending'
         RETURNING *",
        [$countAttempt, $error, $c->event_id, $c->token],
        [ParameterType::INTEGER]
      );
      if ($row === false) {
        $this->logger->warning("[ddd outbox] lease lost on deadLetter of {$c->event_id}; result discarded");
        return false;
      }
      $conn->executeStatement(
        "INSERT INTO {$this->dlq} (event_id, event_type, event_class, integration_action, correlation_id, sequence, command_id,
            payload, payload_signature, signature_json, is_unique, max_attempts, due_at, blog_id, error, attempts)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
          $row['event_id'], $row['event_type'], $row['event_class'], $row['integration_action'], $row['correlation_id'],
          $row['sequence'], $row['command_id'], $row['payload'], $row['payload_signature'], $row['signature_json'],
          $row['is_unique'], $row['max_attempts'], $row['due_at'], $row['blog_id'], $error, $row['attempts'],
        ],
        [10 => ParameterType::BOOLEAN]
      );
      return true;
    });
  }

  /**
   * The rows claim() dead-lettered because their lease expired max_attempts
   * times, with the error stored in the DLQ, since the last call; the list
   * is emptied. The core relay step (OutboxProcessor) takes them after every
   * claim, emits OutboxDeadLettered and lists them in
   * ProcessingResult::$claim_dead_letters, so a claim-time dead letter is
   * as visible as a relay-side one (IReportsClaimDeadLetters, CR-W4CE-9).
   *
   * @return list<array{0: Claim, 1: string}>
   */
  public function take_claim_dead_letters(): array {
    $taken = $this->claim_dead_letters;
    $this->claim_dead_letters = [];
    return $taken;
  }

  /** The fact class of a claimed (or any) row; null when the writer did not know it. */
  public function event_class_of(string $eventId): ?string {
    if (array_key_exists($eventId, $this->claimedClasses)) {
      return $this->claimedClasses[$eventId];
    }
    $class = $this->connection->fetchOne("SELECT event_class FROM {$this->outbox} WHERE event_id = ?", [$eventId]);
    return is_string($class) ? $class : null;
  }

  /** @param array<string, mixed> $row */
  public static function record_from_row(array $row): OutboxRecord {
    $signature = $row['signature_json'] ?? null;
    return new OutboxRecord(
      event_id: (string) $row['event_id'],
      event_type: (string) $row['event_type'],
      integration_action: (string) $row['integration_action'],
      correlation_id: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
      sequence: $row['sequence'] === null ? null : (int) $row['sequence'],
      command_id: $row['command_id'] === null ? null : (string) $row['command_id'],
      payload: (array) json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
      due_at: Time::from_db((string) $row['due_at']),
      is_unique: (bool) $row['is_unique'],
      payload_signature: $signature === null ? null : (array) json_decode((string) $signature, true, 512, JSON_THROW_ON_ERROR),
      max_attempts: (int) $row['max_attempts'],
      blog_id: $row['blog_id'] === null ? null : (int) $row['blog_id'],
    );
  }

  private function unclaim(Claim $c): void {
    $this->connection->executeStatement(
      "UPDATE {$this->outbox} SET claim_token = NULL, lease_until = NULL WHERE event_id = ? AND claim_token = ?",
      [$c->event_id, $c->token]
    );
  }

  /** @param list<mixed> $params */
  private function fenced(string $sql, array $params, Claim $c, string $what): bool {
    $n = $this->connection->executeStatement($sql, $params);
    if ($n === 0) {
      $this->logger->warning("[ddd outbox] lease lost on {$what} of {$c->event_id}; result discarded");
      return false;
    }
    return true;
  }

  private static function json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

  /** Recursively key-sorted, so signatures equal as PHP `==` arrays hash alike. */
  private static function canonical(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value);
    }
    return array_map(self::canonical(...), $value);
  }
}
