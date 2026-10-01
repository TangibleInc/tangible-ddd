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
 * 5.3), executed by Drain::run_once(). It is both
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
 *   reference. shares_connection() is true for a PdoOutboxStore on the
 *   same IHostConnection, so the relay runs submit + accept in one
 *   transaction.
 *
 * claim_due() leases due rows (both kinds) in one short transaction of its
 * own (`FOR UPDATE SKIP LOCKED`), oldest due first, and refuses to run
 * inside an open transaction. complete() deletes the row and retry_later()
 * counts an attempt and sets next_attempt_at; both are fenced on
 * (idempotency_key, claim_token) and return false when the lease was lost.
 * delivery_of() returns the fact of a claimed `deliver` job.
 *
 * Wave 5 (AW2, schema 011): the fact of a parked resume
 * (WakeupIntent::$fact) is kept in `{prefix}ddd_job_facts`, written by
 * schedule() in the job's transaction, returned by claim_due() and deleted
 * with the job by complete() and cancel(). This class does not declare
 * ICarriesFacts: the runner parks a contended fact resume only on
 * PdoParkingJobStore, which DurableRuntime composes (a store built directly
 * keeps the wave-3 delivery retry).
 */
class PdoJobStore implements IWakeupScheduler, ITransport {

  private readonly string $jobs;
  private readonly string $facts;
  private readonly string $outbox;
  private readonly IClock $clock;
  private readonly LoggerInterface $logger;

  /** @var list<string>|null kind values claim_due() is limited to; null = every kind */
  private ?array $claimKinds = null;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    string $tablePrefix = '',
    ?IClock $clock = null,
    ?LoggerInterface $logger = null,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->jobs = $tables->table('ddd_jobs');
    $this->facts = $tables->table('ddd_job_facts');
    $this->outbox = $tables->table('ddd_outbox');
    $this->clock = $clock ?? new SystemClock();
    $this->logger = $logger ?? new NullLogger();
  }

  public function connection(): IHostConnection {
    return $this->db;
  }

  /**
   * A view of the same table whose claim_due() leases only rows of $kinds
   * (wave3-pdo-compose CR-PC-1). DurableRuntime gives the drain's wakeup
   * stage the wakeup kinds and PdoDeliveryWorker the `deliver` kind, so a
   * deliver job is never handed to the process wake handler and a wakeup
   * never to the delivery invoker. Everything else (schedule, cancel,
   * submit, complete, retry_later) is unchanged and shares the connection.
   *
   * @throws \InvalidArgumentException when no kind is given
   */
  public function claiming(WakeKind ...$kinds): self {
    if ($kinds === []) {
      throw new \InvalidArgumentException('claiming() needs at least one WakeKind');
    }
    $view = clone $this;
    $view->claimKinds = array_values(array_unique(array_map(static fn (WakeKind $k) => $k->value, $kinds)));
    return $view;
  }

  // ── IWakeupScheduler ──────────────────────────────────────────────────────

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    $due = Utc::to_db($i->due_at);
    $inserted = $this->db->execute(
      "INSERT INTO `{$this->jobs}`
         (idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at, next_attempt_at, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE id = id",
      [$i->key, $i->kind->value, $i->consumer, $i->process_id, $i->step_index, $i->expected_status, $due, $due, $this->now()]
    );
    if ($i->fact !== null && $inserted > 0) {
      // A new job: its fact replaces any row a repair left behind for the key.
      $this->db->execute(
        "INSERT INTO `{$this->facts}` (idempotency_key, fact) VALUES (?, ?) AS new ON DUPLICATE KEY UPDATE fact = new.fact",
        [$i->key, OutboxRows::json($i->fact)]
      );
    }
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    $this->db->execute(
      "DELETE j, f FROM `{$this->jobs}` j LEFT JOIN `{$this->facts}` f ON f.idempotency_key = j.idempotency_key WHERE j.idempotency_key = ?",
      [$idempotencyKey]
    );
  }

  public function claim_due(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    if ($this->db->in_transaction()) {
      throw new NestedTransactionRejected('IWakeupScheduler::claim_due() must run outside any open transaction.');
    }
    if ($limit <= 0) {
      return [];
    }

    $token = bin2hex(random_bytes(16));
    $nowDb = Utc::to_db($now);
    $leaseUntil = $now->setTimezone(new \DateTimeZone('UTC'))->modify("+{$leaseSeconds} seconds");

    $kindSql = '';
    $kindParams = [];
    if ($this->claimKinds !== null) {
      $kindSql = ' AND kind IN (' . implode(', ', array_fill(0, count($this->claimKinds), '?')) . ')';
      $kindParams = $this->claimKinds;
    }

    $this->db->begin();
    try {
      $ids = array_map(static fn (array $r) => (int) $r['id'], $this->db->fetch_all(
        "SELECT id FROM `{$this->jobs}`
         WHERE due_at <= ? AND next_attempt_at <= ? AND (claim_token IS NULL OR lease_until <= ?)$kindSql
         ORDER BY due_at, id
         LIMIT ?
         FOR UPDATE SKIP LOCKED",
        [$nowDb, $nowDb, $nowDb, ...$kindParams, $limit]
      ));
      $rows = [];
      if ($ids !== []) {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute("UPDATE `{$this->jobs}` SET claim_token = ?, lease_until = ? WHERE id IN ($in)", [$token, Utc::to_db($leaseUntil), ...$ids]);
        $rows = $this->db->fetch_all(
          "SELECT j.id, j.idempotency_key, j.kind, j.consumer, j.process_id, j.step_index, j.expected_status, j.due_at, j.attempts, f.fact
           FROM `{$this->jobs}` j LEFT JOIN `{$this->facts}` f ON f.idempotency_key = j.idempotency_key
           WHERE j.id IN ($in) ORDER BY j.due_at, j.id",
          $ids
        );
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->in_transaction()) {
        $this->db->rollback();
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
        Utc::from_db((string) $row['due_at']),
        (string) $row['idempotency_key'],
        self::fact_of($row),
      ),
      $token,
      $leaseUntil,
      (int) $row['attempts'],
    ), $rows);
  }

  public function complete(ClaimedWakeup $w): bool {
    return $this->fenced(
      "DELETE j, f FROM `{$this->jobs}` j LEFT JOIN `{$this->facts}` f ON f.idempotency_key = j.idempotency_key
       WHERE j.idempotency_key = ? AND j.claim_token = ?",
      [$w->intent->key, $w->token],
      $w,
      'complete'
    );
  }

  public function retry_later(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->fenced(
      "UPDATE `{$this->jobs}` SET attempts = attempts + 1, next_attempt_at = ?, last_error = ?, claim_token = NULL, lease_until = NULL
       WHERE idempotency_key = ? AND claim_token = ?",
      [Utc::to_db($nextAt), $error, $w->intent->key, $w->token],
      $w,
      'retry_later'
    );
  }

  /** Whether any intent (pending or leased) exists for the process: the stranded scan's "live intent". */
  public function has_live_intent(int $processId): bool {
    return $this->db->fetch_one("SELECT 1 AS live FROM `{$this->jobs}` WHERE process_id = ? LIMIT 1", [$processId]) !== null;
  }

  // ── ITransport ────────────────────────────────────────────────────────────

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    $key = 'deliver:' . $c->event_id;
    $due = Utc::to_db($dueAt);
    try {
      $class = $this->db->fetch_one("SELECT event_class FROM `{$this->outbox}` WHERE event_id = ?", [$c->event_id])['event_class'] ?? null;
      $this->db->execute(
        "INSERT INTO `{$this->jobs}`
           (idempotency_key, kind, consumer, event_id, event_type, event_class, integration_action, envelope, due_at, next_attempt_at, created_at)
         VALUES (?, 'deliver', ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id",
        [$key, $this->consumer, $c->event_id, $c->record->event_type, $class, $c->record->integration_action,
         OutboxRows::json($wrappedEnvelope), $due, $due, $this->now()]
      );
      $id = $this->db->fetch_one("SELECT id FROM `{$this->jobs}` WHERE idempotency_key = ?", [$key])['id'] ?? null;
    } catch (\Throwable $e) {
      throw new TransportRejected("Could not write the deliver job for {$c->event_id}: " . $e->getMessage(), 0, $e);
    }
    if ($id === null || (int) $id <= 0) {
      throw new TransportRejected("The deliver job for {$c->event_id} has no id");
    }
    return 'job:' . (int) $id;
  }

  public function shares_connection(IOutboxStore $store): bool {
    return $store instanceof PdoOutboxStore && $store->connection() === $this->db;
  }

  /** The fact of a claimed `deliver` job; null for a wakeup or a vanished row. */
  public function delivery_of(ClaimedWakeup $w): ?DeliveryJob {
    if ($w->intent->kind !== WakeKind::Deliver) {
      return null;
    }
    $row = $this->db->fetch_one(
      "SELECT event_id, event_type, event_class, integration_action, envelope FROM `{$this->jobs}` WHERE idempotency_key = ?",
      [$w->intent->key]
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

  /**
   * @param array<string, mixed> $row
   * @return array{class: string, payload: array<string, mixed>, event_id: string}|null
   */
  private static function fact_of(array $row): ?array {
    if (($row['fact'] ?? null) === null) {
      return null;
    }
    try {
      $fact = json_decode((string) $row['fact'], true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new \RuntimeException("The fact of job {$row['idempotency_key']} does not decode: " . $e->getMessage(), 0, $e);
    }
    if (!is_array($fact)) {
      throw new \RuntimeException("The fact of job {$row['idempotency_key']} is not a JSON object.");
    }
    return $fact;
  }

  /** @param list<mixed> $params */
  private function fenced(string $sql, array $params, ClaimedWakeup $w, string $what): bool {
    if ($this->db->execute($sql, $params) === 0) {
      $this->logger->warning("[ddd jobs] lease lost on {$what} of {$w->intent->key}; result discarded");
      return false;
    }
    return true;
  }

  private function assertInTransaction(string $op): void {
    if (!$this->db->in_transaction()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction.");
    }
  }

  private function now(): string {
    return Utc::to_db($this->clock->now());
  }
}
