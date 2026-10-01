<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Defaults\Pdo\Internal\OutboxRows;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Runtime\SystemClock;

/**
 * `{prefix}ddd_jobs`: the pdo host's durable work queue (register 3.5, 3.6,
 * 5.3), executed by Drain::runOnce(). It is both
 *
 * - the IWakeupScheduler: wakeup intents (continue, timeout, resume_retry)
 *   are rows. schedule()/cancel() throw WakeupOutsideTransaction unless a
 *   transaction is open on the host connection, so an intent commits or
 *   rolls back with the process save (C8, C9); scheduling an existing
 *   idempotency key is a no-op. due_at is ABSOLUTE UTC.
 * - the ITransport of the relay: submit() writes one `deliver` job per fact
 *   (key `deliver:{event_id}`) carrying the wrapped envelope, due at the
 *   absolute $dueAt with no relative delay (bug 3), and returns `job:{id}`.
 *   Resubmitting a fact whose job is still pending returns that job's
 *   reference. sharesConnectionWith() is true for a PdoOutboxStore on the
 *   same IHostConnection, so the relay runs submit + accept in one
 *   transaction.
 *
 * claimDue() leases due rows (both kinds) in one short transaction of its
 * own (`FOR UPDATE SKIP LOCKED`), oldest due first, and refuses to run
 * inside an open transaction. complete() deletes the row and retryLater()
 * counts an attempt and sets next_attempt_at; both are fenced on
 * (idempotency_key, claim_token) and return false when the lease was lost.
 * deliveryOf() returns the fact of a claimed `deliver` job.
 */
final class PdoJobStore implements IWakeupScheduler, ITransport {

  private readonly string $jobs;
  private readonly string $outbox;
  private readonly IClock $clock;
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    string $tablePrefix = '',
    ?IClock $clock = null,
    ?LoggerInterface $logger = null,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->jobs = $tables->table('ddd_jobs');
    $this->outbox = $tables->table('ddd_outbox');
    $this->clock = $clock ?? new SystemClock();
    $this->logger = $logger ?? new NullLogger();
  }

  public function connection(): IHostConnection {
    return $this->db;
  }

  // ── IWakeupScheduler ──────────────────────────────────────────────────────

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    $due = Utc::toDb($i->dueAt);
    $this->db->execute(
      "INSERT INTO `{$this->jobs}`
         (idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at, next_attempt_at, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE id = id",
      [$i->idempotencyKey, $i->kind->value, $i->consumer, $i->processId, $i->stepIndex, $i->expectedStatus, $due, $due, $this->now()]
    );
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    $this->db->execute("DELETE FROM `{$this->jobs}` WHERE idempotency_key = ?", [$idempotencyKey]);
  }

  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    if ($this->db->inTransaction()) {
      throw new NestedTransactionRejected('IWakeupScheduler::claimDue() must run outside any open transaction.');
    }
    if ($limit <= 0) {
      return [];
    }

    $token = bin2hex(random_bytes(16));
    $nowDb = Utc::toDb($now);
    $leaseUntil = $now->setTimezone(new \DateTimeZone('UTC'))->modify("+{$leaseSeconds} seconds");

    $this->db->begin();
    try {
      $ids = array_map(static fn (array $r) => (int) $r['id'], $this->db->fetchAll(
        "SELECT id FROM `{$this->jobs}`
         WHERE due_at <= ? AND next_attempt_at <= ? AND (claim_token IS NULL OR lease_until <= ?)
         ORDER BY due_at, id
         LIMIT ?
         FOR UPDATE SKIP LOCKED",
        [$nowDb, $nowDb, $nowDb, $limit]
      ));
      $rows = [];
      if ($ids !== []) {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute("UPDATE `{$this->jobs}` SET claim_token = ?, lease_until = ? WHERE id IN ($in)", [$token, Utc::toDb($leaseUntil), ...$ids]);
        $rows = $this->db->fetchAll(
          "SELECT id, idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at, attempts
           FROM `{$this->jobs}` WHERE id IN ($in) ORDER BY due_at, id",
          $ids
        );
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }

    return array_map(static fn (array $row) => new ClaimedWakeup(
      new WakeupIntent(
        WakeKind::from((string) $row['kind']),
        (string) $row['consumer'],
        $row['process_id'] === null ? null : (int) $row['process_id'],
        $row['step_index'] === null ? null : (int) $row['step_index'],
        $row['expected_status'] === null ? null : (string) $row['expected_status'],
        Utc::fromDb((string) $row['due_at']),
        (string) $row['idempotency_key'],
      ),
      $token,
      $leaseUntil,
      (int) $row['attempts'],
    ), $rows);
  }

  public function complete(ClaimedWakeup $w): bool {
    return $this->fenced(
      "DELETE FROM `{$this->jobs}` WHERE idempotency_key = ? AND claim_token = ?",
      [$w->intent->idempotencyKey, $w->claimToken],
      $w,
      'complete'
    );
  }

  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->fenced(
      "UPDATE `{$this->jobs}` SET attempts = attempts + 1, next_attempt_at = ?, last_error = ?, claim_token = NULL, lease_until = NULL
       WHERE idempotency_key = ? AND claim_token = ?",
      [Utc::toDb($nextAt), $error, $w->intent->idempotencyKey, $w->claimToken],
      $w,
      'retryLater'
    );
  }

  /** Whether any intent (pending or leased) exists for the process: the stranded scan's "live intent". */
  public function hasLiveIntent(int $processId): bool {
    return $this->db->fetchOne("SELECT 1 AS live FROM `{$this->jobs}` WHERE process_id = ? LIMIT 1", [$processId]) !== null;
  }

  // ── ITransport ────────────────────────────────────────────────────────────

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    $key = 'deliver:' . $c->event_id;
    $due = Utc::toDb($dueAt);
    try {
      $class = $this->db->fetchOne("SELECT event_class FROM `{$this->outbox}` WHERE event_id = ?", [$c->event_id])['event_class'] ?? null;
      $this->db->execute(
        "INSERT INTO `{$this->jobs}`
           (idempotency_key, kind, consumer, event_id, event_type, event_class, integration_action, envelope, due_at, next_attempt_at, created_at)
         VALUES (?, 'deliver', ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id",
        [$key, $this->consumer, $c->event_id, $c->record->event_type, $class, $c->record->integration_action,
         OutboxRows::json($wrappedEnvelope), $due, $due, $this->now()]
      );
      $id = $this->db->fetchOne("SELECT id FROM `{$this->jobs}` WHERE idempotency_key = ?", [$key])['id'] ?? null;
    } catch (\Throwable $e) {
      throw new TransportRejected("Could not write the deliver job for {$c->event_id}: " . $e->getMessage(), 0, $e);
    }
    if ($id === null || (int) $id <= 0) {
      throw new TransportRejected("The deliver job for {$c->event_id} has no id");
    }
    return 'job:' . (int) $id;
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    return $store instanceof PdoOutboxStore && $store->connection() === $this->db;
  }

  /** The fact of a claimed `deliver` job; null for a wakeup or a vanished row. */
  public function deliveryOf(ClaimedWakeup $w): ?DeliveryJob {
    if ($w->intent->kind !== WakeKind::Deliver) {
      return null;
    }
    $row = $this->db->fetchOne(
      "SELECT event_id, event_type, event_class, integration_action, envelope FROM `{$this->jobs}` WHERE idempotency_key = ?",
      [$w->intent->idempotencyKey]
    );
    if ($row === null) {
      return null;
    }
    return new DeliveryJob(
      (string) $row['event_id'],
      (string) $row['event_type'],
      $row['event_class'] === null ? null : (string) $row['event_class'],
      (string) $row['integration_action'],
      (array) json_decode((string) $row['envelope'], true, 512, JSON_THROW_ON_ERROR),
    );
  }

  /** @param list<mixed> $params */
  private function fenced(string $sql, array $params, ClaimedWakeup $w, string $what): bool {
    if ($this->db->execute($sql, $params) === 0) {
      $this->logger->warning("[ddd jobs] lease lost on {$what} of {$w->intent->idempotencyKey}; result discarded");
      return false;
    }
    return true;
  }

  private function assertInTransaction(string $op): void {
    if (!$this->db->inTransaction()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction.");
    }
  }

  private function now(): string {
    return Utc::toDb($this->clock->now());
  }
}
