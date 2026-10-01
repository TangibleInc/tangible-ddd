<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Support\Log;

/**
 * The portable drain bracket (register 3.5, 5.1; X8). Replaces the per-hook
 * brackets of `integration_action()` / `integration_listener()` with ONE
 * invoker per fact:
 *
 *   unwrap → Correlation::within(for_fact(event_id)) → each subscriber in
 *   priority order, skipping those already in the ledger.
 *
 * Per subscriber: success → markDelivered; a throw → markFailed with the
 * next attempt number, logged, and delivery CONTINUES with the next
 * subscriber. When a subscriber's attempts reach the budget its handler is
 * never run again for that fact, and its onExhausted compensation (D1: the
 * failureCommand() of an IExternalEffectCommand) fires. Only after the
 * callback returns is the terminal marker (IDeliveryLedger::markExhausted)
 * written and the subscriber reported `exhausted`.
 *
 * Compensation is durable and retryable: a throwing onExhausted is logged
 * (not propagated), the marker is NOT written, and the subscriber is
 * reported `failed` so needsRetry() stays true. A crash between the final
 * markFailed() and the marker leaves the same state. On every later
 * delivery a pair with attempts >= budget and no marker re-fires the
 * callback (with a DeliveryBudgetExhausted carrying the ledger's lastError)
 * until it succeeds. The guarantee is at-least-once, effectively once after
 * success: the callback must be idempotent (deterministic command id), since
 * a crash after it returned but before the marker commits re-fires it.
 *
 * Each subscriber's command commits in its own transaction (the bus's
 * Transaction middleware); the invoker opens none.
 *
 * Error behaviour: \InvalidArgumentException for a non-IIntegrationEvent
 * class or a fact without an event id (the ledger needs it). A hydration
 * error (from_payload) first counts one failed attempt against every
 * pending subscriber, then propagates while any of them has budget left;
 * once none has, they are exhausted and the fact stops (see poisoned()).
 * Ledger storage errors propagate: the fact is retried as a whole by the
 * host's delivery runner, and that runner's own retry limit (Messenger
 * retry_strategy, a failed Action Scheduler action) bounds it, since a
 * ledger that cannot be written cannot count.
 *
 * Compatibility edge (0.6 hosts): 0.6 hook closures, e.g. the ProcessRunner
 * ignition closure, still run for payloads WITHOUT `__event_id` (a consumer
 * firing the hook by hand, a hand-built payload), just without dedup. This invoker
 * refuses them on purpose, because a ledger row needs the id. A host adapter
 * that wraps legacy hooks MUST therefore route id-less payloads around the
 * invoker: call the subscriber callbacks directly, unledgered, once each in
 * the same priority order, and log that it did so. Otherwise an existing
 * listener silently stops firing. (Wave-3 wp adapter brief item; see
 * packages/ddd-core/src/Runtime/API-CHANGE-REQUESTS.md.)
 *
 * A fact without a correlation id still runs in a Fact scope, rooted in a
 * fresh story, so every subscriber sees `Kind::Fact` as its cause.
 */
final class IntegrationDelivery {

  public const DEFAULT_BUDGET = 5;

  /** @param LoggerInterface|null $log PSR-3 logger; null: host logger, else error_log() */
  public function __construct(
    private readonly ISubscriptionRegistry $registry,
    private readonly IDeliveryLedger $ledger,
    private readonly int $budget = self::DEFAULT_BUDGET,
    private readonly ?LoggerInterface $log = null,
  ) {
    if ($budget < 1) {
      throw new \InvalidArgumentException('Delivery budget must be at least 1');
    }
  }

  /**
   * Handler-execution backoff (5.1): 30 s × 2^(n-1), capped at 3600 s, where
   * $attempt is the failed attempt count (>= 1). Hosts schedule retries with it.
   */
  public static function backoffSeconds(int $attempt): int {
    $n = max(1, $attempt) - 1;
    return $n >= 7 ? 3600 : min(3600, 30 * (2 ** $n));
  }

  /** @param array<string, mixed> $wrapped the envelope-wrapped payload */
  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {
    if (!is_a($eventClass, IIntegrationEvent::class, true)) {
      throw new \InvalidArgumentException("$eventClass must implement IIntegrationEvent");
    }

    $envelope = IntegrationEnvelope::unwrap($wrapped);
    if ($envelope->event_id === null || $envelope->event_id === '') {
      throw new \InvalidArgumentException("Fact $eventClass has no __event_id; it cannot be delivered with a ledger");
    }
    $eventId = $envelope->event_id;

    $ctx = ($envelope->trace_context() ?? TraceContext::root())->for_fact($eventId, $eventClass);

    return Correlation::within($ctx, fn () => $this->run($eventClass, $eventId, $envelope->payload));
  }

  /** @param array<string, mixed> $payload */
  private function run(string $eventClass, string $eventId, array $payload): DeliveryOutcome {
    $subscribers = $this->registry->for($eventClass);
    $delivered = $skipped = $failed = $exhausted = [];

    if ($subscribers === []) {
      return new DeliveryOutcome([], [], [], []);
    }

    try {
      /** @var IIntegrationEvent $event */
      $event = $eventClass::from_payload($payload);
    } catch (\Throwable $decode) {
      return $this->poisoned($subscribers, $eventClass, $eventId, $decode);
    }

    foreach ($subscribers as $s) {
      if ($this->ledger->delivered($s->id, $eventId)) {
        $skipped[] = $s->id;
        continue;
      }

      if ($this->ledger->exhausted($s->id, $eventId)) {
        $exhausted[] = $s->id;
        continue;
      }

      $attempts = $this->ledger->attempts($s->id, $eventId);
      if ($attempts >= $this->budget) {
        // Budget reached but no terminal marker: the compensation never
        // completed (callback threw, or the process died after markFailed).
        $last = new DeliveryBudgetExhausted($s->id, $eventId, $attempts, $this->ledger->lastError($s->id, $eventId));
        if ($this->exhaust($s, $event, $eventId, $last)) {
          $exhausted[] = $s->id;
        } else {
          $failed[] = $s->id;
        }
        continue;
      }

      try {
        ($s->handle)($event, $eventId);
      } catch (\Throwable $e) {
        $attempt = $attempts + 1;
        $this->ledger->markFailed($s->id, $eventId, $e->getMessage(), $attempt);
        Log::write($this->log, sprintf(
          '[ddd delivery] subscriber %s failed on %s event %s (attempt %d/%d): %s',
          $s->id, $eventClass, $eventId, $attempt, $this->budget, $e->getMessage()
        ));

        if ($attempt >= $this->budget && $this->exhaust($s, $event, $eventId, $e)) {
          $exhausted[] = $s->id;
        } else {
          $failed[] = $s->id;
        }
        continue;
      }

      $this->ledger->markDelivered($s->id, $eventId);
      $delivered[] = $s->id;
    }

    return new DeliveryOutcome($delivered, $skipped, $failed, $exhausted);
  }

  /**
   * A poison fact (from_payload() throws): count one failed attempt against
   * every subscriber still pending for it, so the 5.1 budget bounds it
   * (wave1-notes core minor 1). A subscriber that reaches the budget is
   * marked exhausted WITHOUT its onExhausted callback, because there is no
   * event to hand it; that is logged. While any subscriber has budget left
   * the decode error is rethrown and the host retries the fact; once none
   * has, the outcome is returned (nothing pending) and the fact stops.
   *
   * @param list<Subscriber> $subscribers
   */
  private function poisoned(array $subscribers, string $eventClass, string $eventId, \Throwable $decode): DeliveryOutcome {
    $skipped = $failed = $exhausted = [];
    $error = 'decode failed: ' . $decode->getMessage();

    foreach ($subscribers as $s) {
      if ($this->ledger->delivered($s->id, $eventId)) {
        $skipped[] = $s->id;
        continue;
      }
      if ($this->ledger->exhausted($s->id, $eventId)) {
        $exhausted[] = $s->id;
        continue;
      }

      $attempt = $this->ledger->attempts($s->id, $eventId) + 1;
      $this->ledger->markFailed($s->id, $eventId, $error, $attempt);

      if ($attempt >= $this->budget) {
        $this->ledger->markExhausted($s->id, $eventId);
        $exhausted[] = $s->id;
        Log::write($this->log, sprintf(
          '[ddd delivery] %s event %s cannot be decoded; subscriber %s exhausted its budget (%d) without compensation: %s',
          $eventClass, $eventId, $s->id, $this->budget, $decode->getMessage()
        ), 'error');
      } else {
        $failed[] = $s->id;
      }
    }

    if ($failed !== []) {
      throw $decode;
    }

    return new DeliveryOutcome([], $skipped, [], $exhausted);
  }

  /**
   * Runs the compensation, then writes the terminal marker. Returns false
   * (marker NOT written, subscriber stays compensation-pending) when the
   * callback threw; the throw is logged, not propagated, so the remaining
   * subscribers still run. Ledger errors propagate.
   */
  private function exhaust(Subscriber $s, IIntegrationEvent $event, string $eventId, \Throwable $last): bool {
    if ($s->onExhausted !== null) {
      try {
        ($s->onExhausted)($event, $last);
      } catch (\Throwable $e) {
        Log::write($this->log, sprintf(
          '[ddd delivery] failure callback of %s for event %s threw; compensation stays pending and is retried: %s',
          $s->id, $eventId, $e->getMessage()
        ));
        return false;
      }
    }
    $this->ledger->markExhausted($s->id, $eventId);
    return true;
  }
}
