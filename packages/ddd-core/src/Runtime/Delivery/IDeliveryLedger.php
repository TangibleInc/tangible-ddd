<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Per-(subscriber, event_id) delivery ledger (register 3.5, 5.1).
 *
 * - delivered(): true once markDelivered() has committed for the pair.
 * - markFailed(): records the error and sets the attempt count to $attempt
 *   (the 1-based handler attempt that just failed).
 * - attempts(): failed handler attempts so far; the per-subscriber budget
 *   counter (D1). 0 for an unknown pair.
 *
 * Error behaviour: storage failures throw; IntegrationDelivery lets them
 * propagate so the whole fact is retried (at-least-once per subscriber).
 *
 * Connection rules: the host connection. A crash between a subscriber's
 * commit and markDelivered() re-runs that subscriber, so listeners stay
 * idempotent (helped by deterministic command ids).
 */
interface IDeliveryLedger {

  public function delivered(string $subscriberId, string $eventId): bool;

  public function markDelivered(string $subscriberId, string $eventId): void;

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void;

  public function attempts(string $subscriberId, string $eventId): int;
}
