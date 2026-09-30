<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

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
 * subscriber. When a subscriber's attempts reach the budget it is
 * exhausted: its onExhausted callback fires exactly once (D1: the
 * failureCommand() of an IExternalEffectCommand) and it is never run again
 * for that fact. A throwing onExhausted is logged, never propagated.
 *
 * Each subscriber's command commits in its own transaction (the bus's
 * Transaction middleware); the invoker opens none.
 *
 * Error behaviour: \InvalidArgumentException for a non-IIntegrationEvent
 * class or a fact without an event id (the ledger needs it). Hydration
 * errors (from_payload) and ledger storage errors propagate: the fact is
 * retried as a whole by the host's delivery runner.
 *
 * A fact without a correlation id still runs in a Fact scope, rooted in a
 * fresh story, so every subscriber sees `Kind::Fact` as its cause.
 */
final class IntegrationDelivery {

  public const DEFAULT_BUDGET = 5;

  /** @param (\Closure(string):void)|null $log */
  public function __construct(
    private readonly ISubscriptionRegistry $registry,
    private readonly IDeliveryLedger $ledger,
    private readonly int $budget = self::DEFAULT_BUDGET,
    private readonly ?\Closure $log = null,
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

    /** @var IIntegrationEvent $event */
    $event = $eventClass::from_payload($payload);

    foreach ($subscribers as $s) {
      if ($this->ledger->delivered($s->id, $eventId)) {
        $skipped[] = $s->id;
        continue;
      }

      $attempts = $this->ledger->attempts($s->id, $eventId);
      if ($attempts >= $this->budget) {
        $exhausted[] = $s->id;
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

        if ($attempt >= $this->budget) {
          $exhausted[] = $s->id;
          $this->exhaust($s, $event, $eventId, $e);
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

  private function exhaust(Subscriber $s, IIntegrationEvent $event, string $eventId, \Throwable $last): void {
    if ($s->onExhausted === null) {
      return;
    }
    try {
      ($s->onExhausted)($event, $last);
    } catch (\Throwable $e) {
      Log::write($this->log, sprintf(
        '[ddd delivery] failure callback of %s for event %s threw: %s',
        $s->id, $eventId, $e->getMessage()
      ));
    }
  }
}
