<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;

/**
 * Optional seam for `delivery.cross-consumer-once` (wave 5, CR-W5C5-4;
 * sf multi-consumer, CR-W5SF-1/2 and R6): a second consumer ("the other
 * consumer") in the same app, with its own subscription registry, delivery
 * ledger and delivery runner. The fixture's own consumer (HostFixture's
 * bus, outbox, relay, ledger and deliver()) raises the facts.
 *
 * - other_subscriptions(): the other consumer's registry. A subscriber
 *   added here makes the other consumer an audience of its fact class, so
 *   HostFixture::relay_once() routes it a copy of every such fact (sf: the
 *   copy addressed to that consumer's facts transport).
 * - other_ledger(): the other consumer's delivery ledger.
 * - deliver_routed(): run the other consumer's delivery runner for every
 *   copy routed to it and not yet delivered by this method, in routing
 *   order (sf: consume that consumer's facts transport).
 * - deliver_other(): the other consumer's delivery runner once for one
 *   wrapped fact (a redelivered copy).
 *
 * HostFixture::deliver_transported() delivers only the raiser's own
 * messages, to the raiser's subscribers.
 */
interface CrossConsumerHost {

  public function other_subscriptions(): ISubscriptionRegistry;

  public function other_ledger(): IDeliveryLedger;

  /** @return list<DeliveryOutcome> */
  public function deliver_routed(string $eventClass): array;

  public function deliver_other(string $eventClass, array $wrapped): DeliveryOutcome;
}
