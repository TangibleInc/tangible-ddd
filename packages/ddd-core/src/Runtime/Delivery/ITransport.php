<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The relay's hand-off from the outbox to the host's delivery mechanism
 * (register 3.5): Action Scheduler on wp, Messenger `ddd_facts` on sf, the
 * `{prefix}_ddd_jobs` table on pdo.
 *
 * submit() schedules delivery at the ABSOLUTE $dueAt (UTC) and returns the
 * transport reference. It never adds a relative delay (bug 3); when $dueAt
 * is not in the future it enqueues for immediate delivery.
 *
 * Error behaviour: submit() must return an acceptance or throw
 * (TransportRejected or the driver error). A null return means "accepted
 * but no reference" only for transports that have none; the relay treats a
 * missing ref from a transport that should have one (a `0` AS action id) as
 * a rejection (`relay.invalid-acceptance`) and never marks the row accepted.
 *
 * Connection rules: sharesConnectionWith() returns true when submit writes
 * through the same connection as the store (AS on the WordPress connection, pdo jobs table,
 * Doctrine transport on the domain DBAL connection); the relay then runs
 * submit + accept in ONE transaction, so relay failures are DB errors only.
 */
interface ITransport {

  /**
   * @param array<string, mixed> $wrappedEnvelope IntegrationEnvelope::wrap() output
   * @throws TransportRejected
   */
  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string;

  public function sharesConnectionWith(IOutboxStore $store): bool;
}
