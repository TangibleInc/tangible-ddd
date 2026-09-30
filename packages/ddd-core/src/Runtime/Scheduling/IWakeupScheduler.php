<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * Durable wakeup intents (register 3.6, 5.3). Intent rows are the source of
 * truth; any transport (Action Scheduler, Messenger) is a projection.
 *
 * Error behaviour:
 * - schedule() and cancel() throw WakeupOutsideTransaction when no
 *   transaction is active on the process store's connection: the intent and
 *   the state change commit together or not at all (C8, C9). Scheduling an
 *   idempotency key that already exists is a no-op. Storage failures throw.
 * - complete() and retryLater() are fenced by the claim token and return
 *   false when the lease was lost; they do not throw for that.
 * - claimDue() runs its own short transaction, outside any open one.
 *
 * Connection rules: the process store's connection.
 * Lifetime: stateless per call. Implementations: pdo `{prefix}_ddd_jobs`,
 * wp `{prefix}_ddd_wakeups` + an AS action projected AT SCHEDULE TIME on the
 * legacy hook with the legacy associative args (R4), sf `ddd_wakeups`, mem.
 */
interface IWakeupScheduler {

  public function schedule(WakeupIntent $i): void;

  public function cancel(string $idempotencyKey): void;

  /** @return list<ClaimedWakeup> due intents, oldest first, leased for $leaseSeconds */
  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array;

  public function complete(ClaimedWakeup $w): bool;

  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool;
}
