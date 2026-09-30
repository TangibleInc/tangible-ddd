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
 * - lastError(): the error recorded by the latest markFailed(); null for an
 *   unknown pair. IntegrationDelivery hands it to a re-fired onExhausted.
 * - markExhausted(): the TERMINAL marker. Written only after the
 *   subscriber's onExhausted compensation returned (or immediately when it
 *   has none). Idempotent. Never written by markFailed().
 * - exhausted(): true once markExhausted() has committed for the pair.
 *
 * The marker is what separates "exhausted and compensated" from "budget
 * reached, compensation never ran" (a throwing callback, or a crash between
 * the final markFailed() and the callback). A pair with attempts >= budget
 * and no marker is compensation-pending: IntegrationDelivery re-fires the
 * callback on the next delivery, and an operator view lists such pairs as
 * stuck, not finished.
 *
 * Error behaviour: storage failures throw; IntegrationDelivery lets them
 * propagate so the whole fact is retried (at-least-once per subscriber).
 *
 * Connection rules: the host connection. A crash between a subscriber's
 * commit and markDelivered() re-runs that subscriber, and a crash between a
 * successful onExhausted and markExhausted() re-fires the compensation, so
 * listeners and failure commands stay idempotent (helped by deterministic
 * command ids).
 */
interface IDeliveryLedger {

  public function delivered(string $subscriberId, string $eventId): bool;

  public function markDelivered(string $subscriberId, string $eventId): void;

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void;

  public function attempts(string $subscriberId, string $eventId): int;

  public function lastError(string $subscriberId, string $eventId): ?string;

  public function markExhausted(string $subscriberId, string $eventId): void;

  public function exhausted(string $subscriberId, string $eventId): bool;
}
