<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Defaults\Pdo\Internal\OutboxRows;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IOutboxStore on MySQL 8 over the host connection (register 3.4, C14-C19,
 * E F4, F13).
 *
 * - append(): an INSERT on the ambient connection, so it commits or rolls
 *   back with the command's PdoTransactionBoundary. Any failure (duplicate
 *   event_id, unencodable payload, driver error) throws OutboxWriteFailed.
 *   is_unique first cancels older UNLEASED `pending` rows with the same
 *   event type and signature (C26).
 * - claim(): the fenced claim, in one short transaction of its own:
 *     SELECT id ... FOR UPDATE SKIP LOCKED LIMIT n; UPDATE ... SET
 *     claim_token, lease_until WHERE id IN (...); SELECT the rows.
 *   Rows are `pending`, due (`due_at <= now`, `next_attempt_at <= now`),
 *   lease-free (no token, or `lease_until <= now`) and not paused. With a
 *   PdoPauseStore on the same connection the pause check is part of the
 *   SELECT; any other IRelayPauseStore is applied between the SELECT and the
 *   UPDATE, so paused rows are never leased. Refuses to run inside an open
 *   transaction (NestedTransactionRejected): it must not silently join, and
 *   commit with, someone else's unit of work.
 * - accept() / retryLater() / deadLetter(): fenced on (event_id,
 *   claim_token, status `pending`); 0 rows = lease lost → false, logged,
 *   nothing thrown. An expired lease nobody re-claimed still matches.
 *   deadLetter() inserts the DLQ row and sets `dlq` in one transaction (or
 *   inside the ambient one when the relay already opened it).
 *
 * pdo addition (not on the port): appendFact() / eventClassOf() keep the
 * fact's PHP class for the delivery job, as ddd-symfony does (CR sf-1).
 */
final class PdoOutboxStore implements IOutboxStore {

  private readonly string $outbox;
  private readonly string $dlq;
  private readonly IClock $clock;
  private readonly LoggerInterface $logger;

  /** The fact class withFactClass() scopes onto plain append() calls. */
  private ?string $scopedClass = null;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly ?IRelayPauseStore $pauses = null,
    string $tablePrefix = '',
    ?IClock $clock = null,
    ?LoggerInterface $logger = null,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->outbox = $tables->table('ddd_outbox');
    $this->dlq = $tables->table('ddd_dlq');
    $this->clock = $clock ?? new SystemClock();
    $this->logger = $logger ?? new NullLogger();
  }

  public function connection(): IHostConnection {
    return $this->db;
  }

  public function append(OutboxRecord $r): void {
    $this->appendFact($r, $this->scopedClass);
  }

  /**
   * Run $work with $eventClass recorded on every plain append() it makes
   * (wave3-pdo-compose CR-PC-2): how FactClassRecordingEventBus gets the
   * fact's PHP class past the core bus, whose OutboxRecord carries none.
   * The scope is restored when $work returns or throws.
   *
   * @template T
   * @param callable():T $work
   * @return T
   */
  public function withFactClass(string $eventClass, callable $work): mixed {
    $previous = $this->scopedClass;
    $this->scopedClass = $eventClass;
    try {
      return $work();
    } finally {
      $this->scopedClass = $previous;
    }
  }

  /** append() plus the fact's PHP class. @throws OutboxWriteFailed */
  public function appendFact(OutboxRecord $r, ?string $eventClass): void {
    try {
      $columns = OutboxRows::columns($r, $eventClass);

      if ($r->is_unique && $columns['payload_signature'] !== null) {
        $this->db->execute(
          "UPDATE `{$this->outbox}` SET status = 'cancelled'
           WHERE status = 'pending' AND claim_token IS NULL AND event_type = ? AND payload_signature = ?",
          [$r->event_type, $columns['payload_signature']]
        );
      }

      $columns += [
        'status' => 'pending',
        'next_attempt_at' => $columns['due_at'],
        'created_at' => Utc::toDb($this->clock->now()),
      ];
      $this->db->execute(OutboxRows::insertSql($this->outbox, $columns), array_values($columns));
    } catch (\Throwable $e) {
      throw new OutboxWriteFailed("Outbox append of {$r->event_id} failed: " . $e->getMessage(), 0, $e);
    }
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    if ($this->db->inTransaction()) {
      throw new NestedTransactionRejected('IOutboxStore::claim() must run outside any open transaction.');
    }
    if ($limit <= 0) {
      return [];
    }

    $token = bin2hex(random_bytes(16));
    $nowDb = Utc::toDb($now);
    $leaseUntil = $now->setTimezone(new \DateTimeZone('UTC'))->modify("+{$leaseSeconds} seconds");

    [$pauseSql, $pauseParams] = $this->sqlPauseFilter($now);
    $filterInPhp = $this->pauses !== null && $pauseSql === null;

    // Filtering in PHP pages through candidates so paused rows do not use up
    // the limit; the bound keeps one claim from scanning a huge paused backlog.
    $page = $filterInPhp ? max($limit, 100) : $limit;
    $maxPages = $filterInPhp ? 20 : 1;

    $this->db->begin();
    try {
      $ids = [];
      for ($p = 0; $p < $maxPages && count($ids) < $limit; $p++) {
        $candidates = $this->db->fetchAll(
          "SELECT id, event_type FROM `{$this->outbox}`
           WHERE status = 'pending'
             AND due_at <= ?
             AND next_attempt_at <= ?
             AND (claim_token IS NULL OR lease_until <= ?)
             " . ($pauseSql ?? '') . "
           ORDER BY due_at, id
           LIMIT ? OFFSET ?
           FOR UPDATE SKIP LOCKED",
          [$nowDb, $nowDb, $nowDb, ...$pauseParams, $page, $p * $page]
        );
        foreach ($candidates as $c) {
          if (count($ids) >= $limit) {
            break;
          }
          if ($filterInPhp && $this->pauses->isPaused((string) $c['event_type'], $now)) {
            continue;
          }
          $ids[] = (int) $c['id'];
        }
        if (count($candidates) < $page) {
          break;
        }
      }

      $rows = [];
      if ($ids !== []) {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute(
          "UPDATE `{$this->outbox}` SET claim_token = ?, lease_until = ? WHERE id IN ($in)",
          [$token, Utc::toDb($leaseUntil), ...$ids]
        );
        $rows = $this->db->fetchAll("SELECT * FROM `{$this->outbox}` WHERE id IN ($in) ORDER BY due_at, id", $ids);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->rollBackQuietly();
      throw $e;
    }

    return array_map(
      static fn (array $row) => new Claim((string) $row['event_id'], $token, $leaseUntil, OutboxRows::record($row), (int) $row['attempts']),
      $rows
    );
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    return $this->fenced(
      "UPDATE `{$this->outbox}` SET status = 'accepted', transport_ref = ?, accepted_at = ?, claim_token = NULL, lease_until = NULL
       WHERE event_id = ? AND claim_token = ? AND status = 'pending'",
      [$transportRef, Utc::toDb($this->clock->now()), $c->event_id, $c->claimToken],
      $c,
      'accept'
    );
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->fenced(
      "UPDATE `{$this->outbox}` SET attempts = attempts + 1, next_attempt_at = ?, last_error = ?, claim_token = NULL, lease_until = NULL
       WHERE event_id = ? AND claim_token = ? AND status = 'pending'",
      [Utc::toDb($nextAt), $error, $c->event_id, $c->claimToken],
      $c,
      'retryLater'
    );
  }

  public function deadLetter(Claim $c, string $error): bool {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->begin();
    }
    try {
      $row = $this->db->fetchOne(
        "SELECT * FROM `{$this->outbox}` WHERE event_id = ? AND claim_token = ? AND status = 'pending' FOR UPDATE",
        [$c->event_id, $c->claimToken]
      );
      if ($row === null) {
        if ($own) {
          $this->db->commit();
        }
        $this->logger->warning("[ddd outbox] lease lost on deadLetter of {$c->event_id}; result discarded");
        return false;
      }

      $this->db->execute(
        "UPDATE `{$this->outbox}` SET status = 'dlq', attempts = attempts + 1, last_error = ?, claim_token = NULL, lease_until = NULL
         WHERE id = ?",
        [$error, (int) $row['id']]
      );
      $columns = array_combine(OutboxRows::SHARED, OutboxRows::sharedValues($row)) + [
        'error' => $error,
        'attempts' => (int) $row['attempts'] + 1,
        'dead_lettered_at' => Utc::toDb($this->clock->now()),
      ];
      $this->db->execute(OutboxRows::insertSql($this->dlq, $columns), array_values($columns));

      if ($own) {
        $this->db->commit();
      }
      return true;
    } catch (\Throwable $e) {
      if ($own) {
        $this->rollBackQuietly();
      }
      throw $e;
    }
  }

  /** The fact class a writer stored with appendFact(); null when unknown. */
  public function eventClassOf(string $eventId): ?string {
    $class = $this->db->fetchOne("SELECT event_class FROM `{$this->outbox}` WHERE event_id = ?", [$eventId])['event_class'] ?? null;
    return $class === null ? null : (string) $class;
  }

  /** @return array{0: ?string, 1: list<string>} SQL fragment + params, or [null, []] when not expressible in SQL */
  private function sqlPauseFilter(\DateTimeImmutable $now): array {
    if (!$this->pauses instanceof PdoPauseStore || $this->pauses->connection() !== $this->db) {
      return [null, []];
    }
    $patterns = $this->pauses->activePatterns($now);
    if ($patterns === []) {
      return ['', []];
    }
    $sql = implode(' ', array_fill(0, count($patterns), "AND NOT REGEXP_LIKE(event_type, ?, 'c')"));
    return [$sql, $patterns];
  }

  /** @param list<mixed> $params */
  private function fenced(string $sql, array $params, Claim $c, string $what): bool {
    if ($this->db->execute($sql, $params) === 0) {
      $this->logger->warning("[ddd outbox] lease lost on {$what} of {$c->event_id}; result discarded");
      return false;
    }
    return true;
  }

  private function rollBackQuietly(): void {
    try {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
    } catch (\Throwable $e) {
      $this->logger->error('[ddd outbox] rollback failed: ' . $e->getMessage());
    }
  }
}
