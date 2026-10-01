<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;

/**
 * The conformance host's subscriptions on WordPress: every Subscriber is
 * bound through the REAL WpHookSubscriptionRegistry (one add_action per
 * subscriber on the fact's legacy integration hook, at its priority; the
 * registry's own callback unwraps the envelope, opens the fact scope and
 * hydrates the fact), with its handle wrapped in a per-subscriber ledger
 * gate.
 *
 * Why a gate: the wave-2 wp registry has no ledger (schema v8 is wave 3,
 * register 8). The gate is the wave-3 "per-callback invoker wrapping of
 * DDD-registered callbacks" in miniature, over the fixture's ledger:
 * already delivered → skipped; success → markDelivered; a throw →
 * markFailed with the next attempt and the rest of do_action continues.
 * Change request WPC-2 records that ddd-wp owns the real one in wave 3.
 *
 * capture() runs one delivery (do_action or an Action Scheduler action)
 * and returns what the gates saw, as the core DeliveryOutcome.
 */
final class LedgerGatedSubscriptions implements ISubscriptionRegistry {

  /** @var array<string, true> integration hooks this test bound callbacks to */
  private array $hooks = [];

  /** @var array{delivered: list<string>, skipped: list<string>, failed: list<string>}|null */
  private ?array $outcome = null;

  public function __construct(
    public readonly WpHookSubscriptionRegistry $inner,
    private readonly IDeliveryLedger $ledger,
  ) {}

  public function add(Subscriber $s): void {
    $hook = IntegrationHookName::resolve($s->eventClassOrMarker);
    if ($hook !== null) {
      $this->hooks[$hook] = true;
    }

    $handle = $s->handle;
    $this->inner->add(new Subscriber(
      $s->id,
      $s->priority,
      $s->eventClassOrMarker,
      function (object $event, string $eventId) use ($s, $handle): void {
        $this->gate($s->id, $eventId, static fn () => $handle($event, $eventId));
      },
      $s->onExhausted,
    ));
  }

  public function for(string $eventClass): array {
    return $this->inner->for($eventClass);
  }

  /** Run $delivery (fires the hook) and return what the gated subscribers did. */
  public function capture(callable $delivery): DeliveryOutcome {
    $this->outcome = ['delivered' => [], 'skipped' => [], 'failed' => []];
    try {
      $delivery();
      return new DeliveryOutcome($this->outcome['delivered'], $this->outcome['skipped'], $this->outcome['failed'], []);
    } finally {
      $this->outcome = null;
    }
  }

  /** @return list<string> */
  public function boundHooks(): array {
    return array_keys($this->hooks);
  }

  private function gate(string $subscriberId, string $eventId, \Closure $run): void {
    if ($eventId !== '' && $this->ledger->delivered($subscriberId, $eventId)) {
      $this->record('skipped', $subscriberId);
      return;
    }

    try {
      $run();
    } catch (\Throwable $e) {
      if ($eventId !== '') {
        $this->ledger->markFailed($subscriberId, $eventId, $e->getMessage(), $this->ledger->attempts($subscriberId, $eventId) + 1);
      }
      $this->record('failed', $subscriberId);
      return;
    }

    if ($eventId !== '') {
      $this->ledger->markDelivered($subscriberId, $eventId);
    }
    $this->record('delivered', $subscriberId);
  }

  private function record(string $list, string $subscriberId): void {
    if ($this->outcome !== null) {
      $this->outcome[$list][] = $subscriberId;
    }
  }
}
