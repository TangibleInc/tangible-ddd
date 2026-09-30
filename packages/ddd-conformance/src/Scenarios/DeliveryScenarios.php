<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\Fixtures\WidgetShipped;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * The PROCESS-FREE delivery scenarios (register section 4, ruling on #71):
 * the per-subscriber ledger and priority order, with STUB subscribers at
 * Subscriber::IGNITION and Subscriber::RESUME standing in for the process
 * runner. The `.process` variants (real runner) are wave 3.
 */
abstract class DeliveryScenarios extends ConformanceTestCase {

  /** @var array<string, int> subscriber id => handler runs */
  protected array $runs = [];

  /** @var list<string> subscriber ids in the order their handlers ran */
  protected array $order = [];

  #[Group('delivery.double-delivery')]
  #[TestDox('delivery.double-delivery: the same fact delivered twice applies each subscriber\'s effect once, stubs included')]
  public function test_delivery_double_delivery(): void {
    $this->subscribe('conformance.listener', Subscriber::LISTENER);
    $this->subscribe('conformance.ignition-stub', Subscriber::IGNITION);
    $this->subscribe('conformance.resume-stub', Subscriber::RESUME);
    $eventId = Uuid::v4();
    $wrapped = self::wrap(new WidgetShipped('w-1'), $eventId);

    $first = $this->host->deliver(WidgetShipped::class, $wrapped);
    $second = $this->host->deliver(WidgetShipped::class, $wrapped);

    $all = ['conformance.listener', 'conformance.ignition-stub', 'conformance.resume-stub'];
    self::assertSame($all, $first->delivered);
    self::assertTrue($first->isComplete());
    self::assertSame([], $second->delivered, 'nothing runs twice');
    self::assertSame($all, $second->skipped, 'every subscriber is a ledger hit');
    self::assertSame(array_fill_keys($all, 1), $this->runs, 'each effect applied once');
    foreach ($all as $sid) {
      self::assertTrue($this->host->ledger()->delivered($sid, $eventId));
    }
  }

  #[Group('delivery.subscriber-isolation')]
  #[TestDox('delivery.subscriber-isolation: B throws once; A and both stubs still run; the retry runs only B')]
  public function test_delivery_subscriber_isolation(): void {
    $this->subscribe('conformance.a', Subscriber::LISTENER);
    $this->subscribe('conformance.b', Subscriber::LISTENER, failTimes: 1);
    $this->subscribe('conformance.ignition-stub', Subscriber::IGNITION);
    $this->subscribe('conformance.resume-stub', Subscriber::RESUME);
    $eventId = Uuid::v4();
    $wrapped = self::wrap(new WidgetShipped('w-1'), $eventId);

    $first = $this->host->deliver(WidgetShipped::class, $wrapped);

    self::assertSame(['conformance.a', 'conformance.ignition-stub', 'conformance.resume-stub'], $first->delivered, 'B\'s throw stops nobody');
    self::assertSame(['conformance.b'], $first->failed);
    self::assertTrue($first->needsRetry());
    self::assertSame(1, $this->host->ledger()->attempts('conformance.b', $eventId));

    $retry = $this->host->deliver(WidgetShipped::class, $wrapped);

    self::assertSame(['conformance.b'], $retry->delivered, 'the retry runs only B');
    self::assertSame(['conformance.a', 'conformance.ignition-stub', 'conformance.resume-stub'], $retry->skipped);
    self::assertTrue($retry->isComplete());
    self::assertSame(
      ['conformance.a' => 1, 'conformance.b' => 2, 'conformance.ignition-stub' => 1, 'conformance.resume-stub' => 1],
      $this->runs,
    );
  }

  #[Group('delivery.phase-order')]
  #[TestDox('delivery.phase-order: subscribers at 100, 99, 50, 10 registered in that order run 10, 50, 99, 100')]
  public function test_delivery_phase_order(): void {
    foreach ([100, 99, 50, 10] as $priority) {
      $this->subscribe("conformance.p$priority", $priority);
    }

    $outcome = $this->host->deliver(WidgetShipped::class, self::wrap(new WidgetShipped('w-1'), Uuid::v4()));

    $expected = ['conformance.p10', 'conformance.p50', 'conformance.p99', 'conformance.p100'];
    self::assertSame($expected, $this->order, 'listener → ignition → resume → later');
    self::assertSame($expected, $outcome->delivered);
  }

  #[Group('delivery.delayed-once')]
  #[TestDox('delivery.delayed-once: due at t0 + D, delivered once, a retry adds no delay; a legacy delayed row past its time goes out at once')]
  public function test_delivery_delayed_once(): void {
    $delay = 120;
    $this->subscribe('conformance.listener', Subscriber::LISTENER, WidgetRegistered::class);
    $t0 = $this->host->clock()->now();
    $id = $this->publishFact(new WidgetRegistered('w-1', $delay));
    $dueAt = $t0->modify("+{$delay} seconds");

    self::assertSame([], $this->host->relayOnce()->claimed, 'not due at t0');
    $this->host->advanceClock($delay - 1);
    self::assertSame([], $this->host->relayOnce()->claimed, 'not due one second early');

    $this->host->advanceClock(1);
    $this->host->rejectNextSubmission();
    self::assertSame([$id], $this->host->relayOnce()->retried, 'due at t0 + D; first submission rejected');

    $this->host->advanceClock(3600);
    self::assertSame([$id], $this->host->relayOnce()->accepted);

    $held = $this->host->transported();
    self::assertCount(1, $held);
    self::assertSame($dueAt->getTimestamp(), $held[0]->dueAt->getTimestamp(), 'the retry kept the absolute due time (no second delay)');
    self::assertLessThanOrEqual($this->host->clock()->now()->getTimestamp(), $held[0]->dueAt->getTimestamp(), 'already due: immediate');

    $outcomes = $this->host->deliverTransported(WidgetRegistered::class);
    self::assertCount(1, $outcomes);
    self::assertSame(['conformance.listener'], $outcomes[0]->delivered);
    self::assertSame([], $this->host->relayOnce()->claimed, 'not relayed again');
    self::assertSame(['conformance.listener' => 1], $this->runs, 'delivered once');

    // A 0.6-written row: delay_seconds > 0, scheduled_at already past.
    $scheduledAt = $this->host->clock()->now()->modify('-60 seconds');
    $legacy = $this->host->seedLegacyDelayedFact(new WidgetRegistered('legacy', 300), 300, $scheduledAt);

    self::assertSame([$legacy], $this->host->relayOnce()->accepted, 'claimed on the first tick');
    $last = $this->last($this->host->transported());
    self::assertSame($legacy, $last->eventId);
    self::assertLessThanOrEqual($this->host->clock()->now()->getTimestamp(), $last->dueAt->getTimestamp(), 'enqueued immediately, the delay is not re-applied');
  }

  /**
   * A stub subscriber recording its runs; throws on its first $failTimes runs.
   *
   * @param class-string $eventClass
   */
  protected function subscribe(string $id, int $priority, string $eventClass = WidgetShipped::class, int $failTimes = 0): void {
    $this->runs[$id] = 0;
    $this->host->subscriptions()->add(new Subscriber($id, $priority, $eventClass, function () use ($id, $failTimes): void {
      $this->runs[$id]++;
      $this->order[] = $id;
      if ($this->runs[$id] <= $failTimes) {
        throw new \RuntimeException("$id fails on run {$this->runs[$id]}");
      }
    }));
  }

  /** @param list<TransportedFact> $held */
  private function last(array $held): TransportedFact {
    self::assertNotEmpty($held);
    return $held[array_key_last($held)];
  }
}
