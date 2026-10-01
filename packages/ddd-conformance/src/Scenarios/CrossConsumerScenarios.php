<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\CrossConsumerHost;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * Multi-consumer delivery (wave 5, CR-W5C5-4; sf CR-W5SF-R6): a fact one
 * consumer raises reaches another consumer's subscriber exactly once under
 * redelivery, on that consumer's own ledger, and a poison copy in the
 * raiser does not hold the other consumer back. Needs CrossConsumerHost
 * (sf only; `-` on mem, pdo and wp).
 */
abstract class CrossConsumerScenarios extends ConformanceTestCase {

  /** The other consumer's subscriber of WidgetRegistered. */
  protected const AUDIENCE = 'conformance.cross.audience';

  /** The raiser's subscriber that refuses `boom-*` widgets forever. */
  protected const POISON = 'conformance.cross.poison';

  #[Group('delivery.cross-consumer-once')]
  #[TestDox('delivery.cross-consumer-once: a fact of consumer A reaches consumer B\'s subscriber once under redelivery, on B\'s ledger only, and a poison copy in A does not hold B')]
  public function test_delivery_cross_consumer_once(): void {
    $other = $this->cross();
    $heard = [];
    $other->other_subscriptions()->add(new Subscriber(self::AUDIENCE, Subscriber::LISTENER, WidgetRegistered::class,
      static function (IIntegrationEvent $e, string $eventId) use (&$heard): void {
        $heard[] = "{$e->widget_id}@$eventId";
      }));
    $this->host->subscriptions()->add(new Subscriber(self::POISON, Subscriber::LISTENER, WidgetRegistered::class,
      static function (IIntegrationEvent $e): void {
        if (str_starts_with($e->widget_id, 'boom')) {
          throw new \RuntimeException("{$e->widget_id} is poison for the raiser");
        }
      }));

    // 1. A raises boom-1: its own copy keeps failing, B's copy is delivered.
    $boom = $this->publish(new WidgetRegistered('boom-1'));
    $this->host->relay_once();
    $own = $this->host->deliver_transported(WidgetRegistered::class);
    self::assertCount(1, $own, 'the raiser\'s own message');
    self::assertContains(self::POISON, $own[0]->failed);
    self::assertNotContains(self::AUDIENCE, [...$own[0]->delivered, ...$own[0]->failed], 'the raiser\'s message is not delivered to B\'s subscriber');

    $routed = $other->deliver_routed(WidgetRegistered::class);
    self::assertCount(1, $routed, 'one copy routed to B');
    self::assertSame([self::AUDIENCE], $routed[0]->delivered);
    self::assertSame(["boom-1@$boom"], $heard);

    // 2. Redelivered copies (a duplicate, a transport retry): once per subscriber.
    $wrapped = self::wrap(new WidgetRegistered('boom-1'), $boom);
    self::assertContains(self::AUDIENCE, $other->deliver_other(WidgetRegistered::class, $wrapped)->skipped);
    self::assertSame([], $other->deliver_routed(WidgetRegistered::class), 'nothing new routed');
    self::assertSame(["boom-1@$boom"], $heard, 'exactly once');
    self::assertTrue($other->other_ledger()->delivered(self::AUDIENCE, $boom), 'on B\'s ledger');
    self::assertFalse($this->host->ledger()->delivered(self::AUDIENCE, $boom), 'not on A\'s');
    self::assertSame(0, $this->host->ledger()->attempts(self::AUDIENCE, $boom));
    self::assertSame(0, $other->other_ledger()->attempts(self::POISON, $boom), 'A\'s failing subscriber is A\'s alone');

    // 3. A's copy of boom-1 is still failing; B gets the next fact anyway.
    self::assertTrue($this->host->deliver(WidgetRegistered::class, $wrapped)->needs_retry());
    $next = $this->publish(new WidgetRegistered('w-2'));
    $this->host->relay_once();
    $routed = $other->deliver_routed(WidgetRegistered::class);
    self::assertSame([[self::AUDIENCE]], array_map(static fn (DeliveryOutcome $o) => $o->delivered, $routed));
    self::assertSame(["boom-1@$boom", "w-2@$next"], $heard, 'B was not held by A\'s poison copy');
    self::assertTrue($this->host->deliver(WidgetRegistered::class, $wrapped)->needs_retry(), 'and A still retries it');
  }

  protected function cross(): CrossConsumerHost {
    if (!$this->host instanceof CrossConsumerHost) {
      $this->skip_for('CR-W5C5-4', 'the host fixture does not implement CrossConsumerHost');
    }
    return $this->host;
  }
}
