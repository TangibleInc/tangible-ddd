<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\DeadLetter;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;

/**
 * In-memory outbox implementing both IOutboxStore and IOutboxAdministration
 * with the SQL hosts' fencing semantics. Enlist it in an
 * InMemoryTransactionBoundary to make appends roll back with the command.
 *
 * Lease expiry (CR-PDO-6, IReportsClaimDeadLetters): a re-claim of an
 * expired lease counts an attempt, and a row reaching max_attempts that way
 * is dead-lettered inside claim().
 */
final class InMemoryOutboxStore implements IOutboxStore, IOutboxAdministration, IReportsClaimDeadLetters, InMemoryTransactional {

  /**
   * @var array<string, array{record: OutboxRecord, status: string, attempts: int, seq: int,
   *   next_attempt_at: ?\DateTimeImmutable, claim_token: ?string, lease_until: ?\DateTimeImmutable,
   *   last_error: ?string, transport_ref: ?string, accepted_at: ?\DateTimeImmutable}>
   */
  private array $rows = [];

  /** @var array<int, DeadLetter> */
  private array $dlq = [];

  private int $seq = 0;

  private int $dlqSeq = 0;

  /** @var list<array{0: Claim, 1: string}> */
  private array $claim_dead_letters = [];

  public function __construct(
    private readonly IClock $clock,
    private readonly ?IRelayPauseStore $pauses = null,
    private readonly ?ITransactionBoundary $boundary = null,
  ) {}

  // ── IOutboxStore ──────────────────────────────────────────────────────────

  public function append(OutboxRecord $r): void {
    if (isset($this->rows[$r->event_id])) {
      throw new OutboxWriteFailed("Duplicate outbox event_id {$r->event_id}");
    }

    if ($r->is_unique) {
      foreach ($this->rows as $id => $row) {
        if ($row['status'] === 'pending'
          && $row['claim_token'] === null
          && $row['record']->event_type === $r->event_type
          && $row['record']->payload_signature == $r->payload_signature) {
          $this->rows[$id]['status'] = 'cancelled';
        }
      }
    }

    $this->rows[$r->event_id] = [
      'record' => $r,
      'status' => 'pending',
      'attempts' => 0,
      'seq' => ++$this->seq,
      'next_attempt_at' => null,
      'claim_token' => null,
      'lease_until' => null,
      'last_error' => null,
      'transport_ref' => null,
      'accepted_at' => null,
    ];
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    if ($this->boundary?->is_active()) {
      throw new NestedTransactionRejected('IOutboxStore::claim() must run outside any open transaction.');
    }

    $due = array_filter($this->rows, function (array $row) use ($now) {
      return $row['status'] === 'pending'
        && $row['record']->due_at <= $now
        && ($row['next_attempt_at'] === null || $row['next_attempt_at'] <= $now)
        && ($row['claim_token'] === null || $row['lease_until'] <= $now)
        && !($this->pauses?->is_paused($row['record']->event_type, $now) ?? false);
    });
    uasort($due, static fn ($a, $b) => [$a['record']->due_at, $a['seq']] <=> [$b['record']->due_at, $b['seq']]);

    $claims = [];
    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");
    foreach (array_slice($due, 0, max(0, $limit), true) as $id => $row) {
      $token = bin2hex(random_bytes(8));
      $this->rows[$id]['claim_token'] = $token;
      $this->rows[$id]['lease_until'] = $leaseUntil;

      // CR-PDO-6: re-claiming an expired lease is an attempt (the previous
      // holder died without an outcome).
      if ($row['claim_token'] !== null) {
        $this->rows[$id]['attempts']++;
        $this->rows[$id]['last_error'] = self::LEASE_EXPIRED_ERROR;
      }
      $claim = new Claim((string) $id, $token, $leaseUntil, $row['record'], $this->rows[$id]['attempts']);

      if ($row['claim_token'] !== null && $claim->attempts >= $claim->record->max_attempts) {
        $error = sprintf('%s %d times; dead-lettered at claim', self::LEASE_EXPIRED_ERROR, $claim->attempts);
        $this->moveToDlq($claim, $error);
        $this->claim_dead_letters[] = [$claim, $error];
        continue;
      }
      $claims[] = $claim;
    }
    return $claims;
  }

  public function take_claim_dead_letters(): array {
    $taken = $this->claim_dead_letters;
    $this->claim_dead_letters = [];
    return $taken;
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    if (!$this->holds($c)) {
      return false;
    }
    $this->rows[$c->event_id]['status'] = 'accepted';
    $this->rows[$c->event_id]['transport_ref'] = $transportRef;
    $this->rows[$c->event_id]['accepted_at'] = $this->clock->now();
    $this->clearLease($c->event_id);
    return true;
  }

  public function retry_later(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    if (!$this->holds($c)) {
      return false;
    }
    $this->rows[$c->event_id]['attempts']++;
    $this->rows[$c->event_id]['next_attempt_at'] = $nextAt;
    $this->rows[$c->event_id]['last_error'] = $error;
    $this->clearLease($c->event_id);
    return true;
  }

  public function dead_letter(Claim $c, string $error): bool {
    if (!$this->holds($c)) {
      return false;
    }
    $this->rows[$c->event_id]['attempts']++;
    $this->moveToDlq($c, $error);
    return true;
  }

  // ── IOutboxAdministration ─────────────────────────────────────────────────

  public function dead_letters(int $limit, ?string $after = null): array {
    $letters = array_values(array_filter(
      $this->dlq,
      static fn (DeadLetter $d) => $after === null || $d->dlq_id > (int) $after
    ));
    return array_slice($letters, 0, max(0, $limit));
  }

  public function retry(string $event_id, bool $force = false): void {
    $row = $this->rows[$event_id] ?? throw new OutboxRowNotFound("Outbox row $event_id not found");

    if ($row['claim_token'] !== null && $row['lease_until'] > $this->clock->now()) {
      throw new OutboxAdministrationRefused("Outbox row $event_id is leased; retry refused");
    }
    if (!$force && !in_array($row['status'], ['pending', 'dlq'], true)) {
      throw new OutboxAdministrationRefused("Outbox row $event_id is {$row['status']}; retry needs force");
    }
    $this->reset($event_id);

    // CR sfc-5: a retried row leaves the DLQ (its dead-letter entries go).
    foreach ($this->dlq as $id => $letter) {
      if ($letter->event_id === $event_id) {
        unset($this->dlq[$id]);
      }
    }
  }

  public function replay(int $dlqId): void {
    $letter = $this->dlq[$dlqId] ?? throw new OutboxRowNotFound("Dead letter #$dlqId not found");

    if (!isset($this->rows[$letter->event_id])) {
      $this->append($letter->record);
    } else {
      $this->reset($letter->event_id);
    }
    unset($this->dlq[$dlqId]);
  }

  public function discard(int $dlqId): void {
    if (!isset($this->dlq[$dlqId])) {
      throw new OutboxRowNotFound("Dead letter #$dlqId not found");
    }
    unset($this->dlq[$dlqId]);
  }

  public function purge(\DateTimeImmutable $olderThan): int {
    $n = 0;
    foreach ($this->rows as $id => $row) {
      if ($row['status'] === 'accepted' && $row['accepted_at'] < $olderThan) {
        unset($this->rows[$id]);
        $n++;
      }
    }
    return $n;
  }

  public function stats(): array {
    $stats = ['pending' => 0, 'accepted' => 0, 'dlq' => 0, 'cancelled' => 0];
    foreach ($this->rows as $row) {
      $stats[$row['status']]++;
    }
    $stats['dead_letters'] = count($this->dlq);
    return $stats;
  }

  // ── InMemoryTransactional ─────────────────────────────────────────────────

  public function snapshot(): mixed {
    return [$this->rows, $this->dlq, $this->seq, $this->dlqSeq];
  }

  public function restore(mixed $state): void {
    [$this->rows, $this->dlq, $this->seq, $this->dlqSeq] = $state;
  }

  // ── test inspection ───────────────────────────────────────────────────────

  public function record_of(string $event_id): ?OutboxRecord {
    return $this->rows[$event_id]['record'] ?? null;
  }

  /** @return list<string> event ids in append order */
  public function event_ids(): array {
    $rows = $this->rows;
    uasort($rows, static fn (array $a, array $b) => $a['seq'] <=> $b['seq']);
    return array_keys($rows);
  }

  public function status_of(string $event_id): ?string {
    return $this->rows[$event_id]['status'] ?? null;
  }

  public function attempts_of(string $event_id): ?int {
    return $this->rows[$event_id]['attempts'] ?? null;
  }

  public function transport_ref_of(string $event_id): ?string {
    return $this->rows[$event_id]['transport_ref'] ?? null;
  }

  /** Simulate a purge/manual delete of the original row. */
  public function forget(string $event_id): void {
    unset($this->rows[$event_id]);
  }

  private function holds(Claim $c): bool {
    $row = $this->rows[$c->event_id] ?? null;
    return $row !== null && $row['status'] === 'pending' && $row['claim_token'] === $c->token;
  }

  /** Status `dlq` plus a DeadLetter entry; the caller has counted the attempt. */
  private function moveToDlq(Claim $c, string $error): void {
    $this->rows[$c->event_id]['status'] = 'dlq';
    $this->rows[$c->event_id]['last_error'] = $error;
    $this->clearLease($c->event_id);

    $id = ++$this->dlqSeq;
    $this->dlq[$id] = new DeadLetter($id, $c->event_id, $error, $this->rows[$c->event_id]['attempts'], $this->clock->now(), $c->record);
  }

  private function clearLease(string $event_id): void {
    $this->rows[$event_id]['claim_token'] = null;
    $this->rows[$event_id]['lease_until'] = null;
  }

  private function reset(string $event_id): void {
    $this->rows[$event_id]['status'] = 'pending';
    $this->rows[$event_id]['attempts'] = 0;
    $this->rows[$event_id]['next_attempt_at'] = $this->clock->now();
    $this->rows[$event_id]['last_error'] = null;
    $this->clearLease($event_id);
  }
}
