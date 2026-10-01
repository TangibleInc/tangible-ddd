<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * Operator repairs over the outbox (register 3.4). The four core repair
 * handlers orchestrate over this port; wp implements it as
 * WpdbOutboxAdministration.
 *
 * Error behaviour:
 * - retry(): throws OutboxRowNotFound for an unknown event_id; throws
 *   OutboxAdministrationRefused for a LEASED row (always, even forced) and
 *   for any status other than `pending`/`dlq` unless $force (O5). Resets
 *   status `pending`, attempts 0, next attempt now, clears lease and error.
 *   A retried `dlq` row leaves the DLQ: its dead-letter entries are deleted
 *   in the same transaction as the reset (CR sfc-5, every host).
 * - replay(): keeps event_id (C22): resets the original outbox row, or
 *   re-inserts it with the original event_id if it was purged, and deletes
 *   the DLQ row, in one transaction. Unknown dlq id: OutboxRowNotFound.
 * - discard(): deletes the DLQ row only. Unknown dlq id: OutboxRowNotFound.
 * - purge(): deletes `accepted` (wp: `completed`) rows older than the cutoff;
 *   returns the count.
 * - Storage failures throw \RuntimeException.
 *
 * Connection rules: host connection; each mutating call is its own
 * transaction (or runs in the repair command's transaction).
 */
interface IOutboxAdministration {

  /** @return list<DeadLetter> oldest first; $after is the last dlqId of the previous page */
  public function deadLetters(int $limit, ?string $after = null): array;

  public function retry(string $event_id, bool $force = false): void;

  public function replay(int $dlqId): void;

  public function discard(int $dlqId): void;

  public function purge(\DateTimeImmutable $olderThan): int;

  /** @return array<string, int> counts keyed by port status plus `dead_letters` */
  public function stats(): array;
}
