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
 * (TransportRejected or the driver error). Every transport MUST issue a
 * reference (ruling CONF-4): a return of null, '' or '0' (e.g. a `0` Action
 * Scheduler action id) is ALWAYS a rejection. The relay retries it per the
 * relay budget and never marks the row accepted (`relay.invalid-acceptance`).
 * The `?string` return type is kept for signature stability only.
 *
 * Connection rules: shares_connection() returns true when submit writes
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

  public function shares_connection(IOutboxStore $store): bool;
}
