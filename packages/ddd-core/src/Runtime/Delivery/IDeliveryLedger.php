<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Per-(subscriber, event_id) delivery ledger (register 3.5, 5.1).
 *
 * UNRATIFIED: last_error(), mark_exhausted() and exhausted() extend the
 * register's four methods; see CR-1 in Runtime/API-CHANGE-REQUESTS.md.
 *
 * - delivered(): true once mark_delivered() has committed for the pair.
 * - mark_failed(): records the error and sets the attempt count to $attempt
 *   (the 1-based handler attempt that just failed).
 * - attempts(): failed handler attempts so far; the per-subscriber budget
 *   counter (D1). 0 for an unknown pair.
 * - last_error(): the error recorded by the latest mark_failed(); null for an
 *   unknown pair. IntegrationDelivery hands it to a re-fired on_exhausted.
 * - mark_exhausted(): the TERMINAL marker. Written only after the
 *   subscriber's on_exhausted compensation returned (or immediately when it
 *   has none). Idempotent. Never written by mark_failed().
 * - exhausted(): true once mark_exhausted() has committed for the pair.
 *
 * The marker is what separates "exhausted and compensated" from "budget
 * reached, compensation never ran" (a throwing callback, or a crash between
 * the final mark_failed() and the callback). A pair with attempts >= budget
 * and no marker is compensation-pending: IntegrationDelivery re-fires the
 * callback on the next delivery, and an operator view lists such pairs as
 * stuck, not finished.
 *
 * Error behaviour: storage failures throw; IntegrationDelivery lets them
 * propagate so the whole fact is retried (at-least-once per subscriber).
 *
 * Connection rules: the host connection. A crash between a subscriber's
 * commit and mark_delivered() re-runs that subscriber, and a crash between a
 * successful on_exhausted and mark_exhausted() re-fires the compensation, so
 * listeners and failure commands stay idempotent (helped by deterministic
 * command ids).
 */
interface IDeliveryLedger {

  public function delivered(string $subscriberId, string $eventId): bool;

  public function mark_delivered(string $subscriberId, string $eventId): void;

  public function mark_failed(string $subscriberId, string $eventId, string $error, int $attempt): void;

  public function attempts(string $subscriberId, string $eventId): int;

  public function last_error(string $subscriberId, string $eventId): ?string;

  public function mark_exhausted(string $subscriberId, string $eventId): void;

  public function exhausted(string $subscriberId, string $eventId): bool;
}
