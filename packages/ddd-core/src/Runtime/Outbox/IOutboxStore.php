<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * Fenced outbox storage (register 3.4). New interface: consumers' legacy
 * IOutboxRepository implementations are bridged by LegacyOutboxStore
 * (wave 3, unfenced, logged warning) rather than gaining methods (R3).
 *
 * Status vocabulary of the port: `pending`, `accepted` (the transport has
 * it), `dlq`, `cancelled`. wp keeps WRITING `completed` (R4/R5) and reads it
 * as `accepted`; pdo and sf store `accepted`.
 *
 * Error behaviour:
 * - append() throws OutboxWriteFailed on any failure (duplicate event_id
 *   included), so a failed insert rolls back the command (C14). When the
 *   record is is_unique, it cancels older UNLEASED `pending` rows with the
 *   same event type and payload signature (C26); leased rows are never touched.
 * - accept(), retry_later() and dead_letter() are fenced with
 *   `WHERE event_id = ? AND claim_token = ?`. 0 rows means the lease was
 *   lost: they return false, the caller discards and logs; nothing throws
 *   (C16-C18). An expired lease that nobody re-claimed still matches.
 * - claim() throws NestedTransactionRejected when called inside an open
 *   transaction (E F13).
 *
 * Connection rules: uses the host connection, the SAME one the domain
 * repositories and ITransactionBoundary use. append() runs inside the
 * ambient command transaction; claim() runs one short transaction of its own
 * and must be called outside any open transaction.
 *
 * Lifetime: stateless per call. The lease length comes from
 * OutboxConfig::lock_timeout_seconds, not a literal (C15).
 */
interface IOutboxStore {

  /** @throws OutboxWriteFailed */
  public function append(OutboxRecord $r): void;

  /**
   * Lease up to $limit rows that are `pending`, due (`due_at <= now` and
   * `next_attempt_at <= now`), not paused (IRelayPauseStore) and lease-free,
   * oldest due first (SKIP LOCKED on SQL hosts).
   *
   * @return list<Claim>
   * @throws \TangibleDDD\Runtime\NestedTransactionRejected
   */
  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array;

  /** Mark accepted by the transport. false = lease lost; caller discards. */
  public function accept(Claim $c, ?string $transportRef): bool;

  /** Back to `pending` with attempts = attempts + 1 (in SQL) and next_attempt_at = $nextAt. false = lease lost. */
  public function retry_later(Claim $c, string $error, \DateTimeImmutable $nextAt): bool;

  /** DLQ insert + status `dlq` in one transaction; the outbox row stays. false = lease lost. */
  public function dead_letter(Claim $c, string $error): bool;
}
