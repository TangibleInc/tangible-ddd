<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Conformance\CrossConsumerHost;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Testing\InMemoryDeliveryLedger;

/**
 * NOT a conformance host (`delivery.cross-consumer-once` is `-` on mem).
 * The mem host plus a SIMULATED second consumer, so CrossConsumerScenarios
 * runs in this package before sf runs it for real: the other consumer has
 * its own subscription registry, delivery ledger (enlisted in the same
 * boundary) and delivery runner. Routing is simulated: every message the
 * transport holds is a copy for the other consumer when its registry
 * subscribes to the message's class.
 */
final class MemCrossConsumerFixture extends MemHostFixture implements CrossConsumerHost {

  public const OTHER_PREFIX = 'conformance_other';

  private SubscriptionRegistry $other_registry;

  private InMemoryDeliveryLedger $other_ledger;

  private int $routed_cursor = 0;

  public function name(): string {
    return 'mem-cross-consumer';
  }

  public function set_up(ScenarioContext $context): void {
    parent::set_up($context);
    $this->other_registry = new SubscriptionRegistry();
    $this->other_ledger = new InMemoryDeliveryLedger(self::OTHER_PREFIX);
    $this->boundary->enlist($this->other_ledger);
    $this->routed_cursor = 0;
  }

  public function other_subscriptions(): ISubscriptionRegistry {
    return $this->other_registry;
  }

  public function other_ledger(): IDeliveryLedger {
    return $this->other_ledger;
  }

  public function deliver_routed(string $eventClass): array {
    $held = $this->held();
    $outcomes = [];
    for (; $this->routed_cursor < count($held); $this->routed_cursor++) {
      if ($this->other_registry->for($eventClass) !== []) {
        $outcomes[] = $this->deliver_other($eventClass, $held[$this->routed_cursor]['envelope']);
      }
    }
    return $outcomes;
  }

  public function deliver_other(string $eventClass, array $wrapped): DeliveryOutcome {
    return (new IntegrationDelivery($this->other_registry, $this->other_ledger, IntegrationDelivery::DEFAULT_BUDGET, $this->logger))
      ->deliver($eventClass, $wrapped);
  }
}
