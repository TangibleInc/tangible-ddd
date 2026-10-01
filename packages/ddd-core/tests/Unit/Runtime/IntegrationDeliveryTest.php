<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Core\Tests\Unit\Fixtures\BillingFact;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\PoisonFact;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Testing\InMemoryDeliveryLedger;

final class IntegrationDeliveryTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private SubscriptionRegistry $registry;
  private InMemoryDeliveryLedger $ledger;
  /** @var list<string> */
  private array $ran = [];

  protected function setUp(): void {
    $this->registry = new SubscriptionRegistry();
    $this->ledger = new InMemoryDeliveryLedger();
    $this->ran = [];
    Correlation::reset();
  }

  private function wrapped(array $payload = ['order_id' => 7, 'sku' => 'x'], ?string $event_id = self::EVENT_ID, ?string $corr = 'corr-9'): array {
    return IntegrationEnvelope::wrap($payload, $corr, 3, $event_id);
  }

  private function stub(string $id, int $priority, string $class = OrderPlaced::class, ?\Closure $body = null, ?\Closure $onExhausted = null): Subscriber {
    return new Subscriber($id, $priority, $class, function (IIntegrationEvent $e, string $event_id) use ($id, $body) {
      $this->ran[] = $id;
      if ($body !== null) {
        $body($e, $event_id);
      }
    }, $onExhausted);
  }

  private function delivery(int $budget = 5, ?\Closure $log = null): IntegrationDelivery {
    return new IntegrationDelivery(
      $this->registry, $this->ledger, $budget,
      $log === null ? new \Psr\Log\NullLogger() : new \TangibleDDD\Core\Tests\Unit\Fixtures\CallbackLogger($log),
    );
  }

  public function test_phase_constants_are_frozen(): void {
    self::assertSame(10, Subscriber::LISTENER);
    self::assertSame(50, Subscriber::IGNITION);
    self::assertSame(99, Subscriber::RESUME);
  }

  public function test_delivery_phase_order_runs_by_numeric_priority(): void {
    // delivery.phase-order: registered 100, 99, 50, 10 → run 10 → 50 → 99 → 100
    $this->registry->add($this->stub('after-resume', 100));
    $this->registry->add($this->stub('resume', Subscriber::RESUME));
    $this->registry->add($this->stub('ignition', Subscriber::IGNITION));
    $this->registry->add($this->stub('listener', Subscriber::LISTENER));

    $outcome = $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['listener', 'ignition', 'resume', 'after-resume'], $this->ran);
    self::assertSame(['listener', 'ignition', 'resume', 'after-resume'], $outcome->delivered);
    self::assertTrue($outcome->isComplete());
  }

  public function test_equal_priorities_keep_registration_order(): void {
    $this->registry->add($this->stub('b', 10));
    $this->registry->add($this->stub('a', 10));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['b', 'a'], $this->ran);
  }

  public function test_marker_interface_subscribers_receive_implementing_facts(): void {
    $this->registry->add($this->stub('billing', 10, BillingFact::class));
    $this->registry->add($this->stub('users', 10, UserJoined::class));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['billing'], $this->ran);
  }

  public function test_subscribers_receive_the_hydrated_event_and_its_event_id(): void {
    $seen = [];
    $this->registry->add($this->stub('l', 10, body: function (IIntegrationEvent $e, string $id) use (&$seen) {
      $seen = [get_class($e), $e->order_id, $e->sku, $id];
    }));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame([OrderPlaced::class, 7, 'x', self::EVENT_ID], $seen);
  }

  public function test_every_subscriber_runs_inside_the_fact_scope_and_the_scope_closes(): void {
    $cause = null;
    $this->registry->add($this->stub('l', 10, body: function () use (&$cause) {
      $ctx = Correlation::peek();
      $cause = [$ctx?->correlation_id, $ctx?->cause?->kind, $ctx?->cause?->id, $ctx?->sequence];
    }));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['corr-9', Kind::Fact, self::EVENT_ID, 3], $cause);
    self::assertNull(Correlation::peek());
  }

  public function test_a_fact_without_a_correlation_id_still_gets_a_fact_scope(): void {
    $kind = null;
    $this->registry->add($this->stub('l', 10, body: function () use (&$kind) {
      $kind = Correlation::peek()?->cause?->kind;
    }));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped(corr: null));

    self::assertSame(Kind::Fact, $kind);
    self::assertNull(Correlation::peek());
  }

  public function test_a_fact_without_an_event_id_is_rejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped(event_id: null));
  }

  public function test_a_non_integration_event_class_is_rejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->delivery()->deliver(\stdClass::class, $this->wrapped());
  }

  public function test_double_delivery_applies_each_subscriber_once(): void {
    // delivery.double-delivery with stub ignition and resume subscribers
    $this->registry->add($this->stub('listener', Subscriber::LISTENER));
    $this->registry->add($this->stub('ignition', Subscriber::IGNITION));
    $this->registry->add($this->stub('resume', Subscriber::RESUME));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());
    $second = $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['listener', 'ignition', 'resume'], $this->ran);
    self::assertSame([], $second->delivered);
    self::assertSame(['listener', 'ignition', 'resume'], $second->skipped);
  }

  public function test_the_ledger_is_per_event_id(): void {
    $this->registry->add($this->stub('listener', 10));

    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());
    $this->delivery()->deliver(OrderPlaced::class, $this->wrapped(event_id: '9f1e2d3c-1f53-4a8e-9f2b-6b8d5f0a9d11'));

    self::assertSame(['listener', 'listener'], $this->ran);
  }

  public function test_subscriber_isolation_a_failure_does_not_stop_later_subscribers(): void {
    // delivery.subscriber-isolation: A ok, B throws once; stubs still run; retry runs only B
    $b_calls = 0;
    $this->registry->add($this->stub('A', Subscriber::LISTENER));
    $this->registry->add($this->stub('B', Subscriber::LISTENER, body: function () use (&$b_calls) {
      if (++$b_calls === 1) {
        throw new \RuntimeException('B flaked');
      }
    }));
    $this->registry->add($this->stub('ignition', Subscriber::IGNITION));
    $this->registry->add($this->stub('resume', Subscriber::RESUME));

    $first = $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['A', 'B', 'ignition', 'resume'], $this->ran);
    self::assertSame(['A', 'ignition', 'resume'], $first->delivered);
    self::assertSame(['B'], $first->failed);
    self::assertTrue($first->needsRetry());
    self::assertSame(1, $this->ledger->attempts('B', self::EVENT_ID));
    self::assertSame('B flaked', $this->ledger->lastError('B', self::EVENT_ID));

    $this->ran = [];
    $retry = $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['B'], $this->ran, 'the retry re-runs only the failed subscriber');
    self::assertSame(['B'], $retry->delivered);
    self::assertSame(['A', 'ignition', 'resume'], $retry->skipped);
    self::assertTrue($retry->isComplete());
  }

  public function test_budget_exhaustion_fires_the_failure_callback_exactly_once(): void {
    $fired = [];
    $this->registry->add($this->stub(
      'charge',
      Subscriber::LISTENER,
      body: static function () { throw new \RuntimeException('gateway down'); },
      onExhausted: function (IIntegrationEvent $e, \Throwable $last) use (&$fired) {
        $fired[] = [get_class($e), $last->getMessage()];
      }
    ));
    $delivery = $this->delivery(budget: 3);

    $o1 = $delivery->deliver(OrderPlaced::class, $this->wrapped());
    $o2 = $delivery->deliver(OrderPlaced::class, $this->wrapped());
    self::assertSame(['charge'], $o1->failed);
    self::assertSame(['charge'], $o2->failed);
    self::assertSame([], $fired);

    $o3 = $delivery->deliver(OrderPlaced::class, $this->wrapped());
    self::assertSame([], $o3->failed);
    self::assertSame(['charge'], $o3->exhausted);
    self::assertFalse($o3->needsRetry());
    self::assertSame([[OrderPlaced::class, 'gateway down']], $fired);

    $o4 = $delivery->deliver(OrderPlaced::class, $this->wrapped());
    self::assertSame(['charge'], $o4->exhausted);
    self::assertCount(3, $this->ran, 'an exhausted subscriber is not run again');
    self::assertCount(1, $fired, 'the failure callback fires once');
    self::assertSame(3, $this->ledger->attempts('charge', self::EVENT_ID));
  }

  public function test_exhaustion_of_one_subscriber_leaves_the_others_delivered(): void {
    $this->registry->add($this->stub('ok', 10));
    $this->registry->add($this->stub('bad', 10, body: static function () { throw new \RuntimeException('x'); }));

    $outcome = $this->delivery(budget: 1)->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['ok'], $outcome->delivered);
    self::assertSame(['bad'], $outcome->exhausted);
    self::assertFalse($outcome->needsRetry());
  }

  public function test_exhaustion_writes_the_terminal_marker_only_after_the_callback_succeeds(): void {
    $markerSeenByCallback = null;
    $this->registry->add($this->stub(
      'charge',
      10,
      body: static function () { throw new \RuntimeException('x'); },
      onExhausted: function () use (&$markerSeenByCallback) {
        $markerSeenByCallback = $this->ledger->exhausted('charge', self::EVENT_ID);
      }
    ));

    $this->delivery(budget: 1)->deliver(OrderPlaced::class, $this->wrapped());

    self::assertFalse($markerSeenByCallback, 'the marker is written after the callback, not before');
    self::assertTrue($this->ledger->exhausted('charge', self::EVENT_ID));
  }

  public function test_exhaustion_without_a_callback_is_marked_terminal(): void {
    $this->registry->add($this->stub('bad', 10, body: static function () { throw new \RuntimeException('x'); }));

    $outcome = $this->delivery(budget: 1)->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['bad'], $outcome->exhausted);
    self::assertTrue($this->ledger->exhausted('bad', self::EVENT_ID));
  }

  public function test_a_throwing_failure_callback_is_retried_until_it_succeeds_and_fires_once_after(): void {
    $logged = [];
    $calls = 0;
    $succeeded = 0;
    $this->registry->add($this->stub(
      'charge',
      10,
      body: static function () { throw new \RuntimeException('gateway down'); },
      onExhausted: function (IIntegrationEvent $e, \Throwable $last) use (&$calls, &$succeeded) {
        if (++$calls === 1) {
          throw new \LogicException('failure command broke');
        }
        $succeeded++;
      }
    ));
    $delivery = $this->delivery(1, static function (string $m) use (&$logged) { $logged[] = $m; });

    $first = $delivery->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['charge'], $first->failed, 'compensation pending: reported as failed, not exhausted');
    self::assertSame([], $first->exhausted);
    self::assertTrue($first->needsRetry());
    self::assertFalse($this->ledger->exhausted('charge', self::EVENT_ID));
    self::assertCount(2, $logged, 'the handler failure, then the callback failure');
    self::assertStringContainsString('failure command broke', $logged[1]);

    $second = $delivery->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['charge'], $second->exhausted);
    self::assertFalse($second->needsRetry());
    self::assertSame(2, $calls);
    self::assertSame(1, $succeeded);
    self::assertCount(1, $this->ran, 'the handler itself is not re-run once over budget');
    self::assertTrue($this->ledger->exhausted('charge', self::EVENT_ID));

    $third = $delivery->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame(['charge'], $third->exhausted);
    self::assertSame(2, $calls, 'no further callback once the marker is written');
  }

  public function test_a_crash_between_mark_failed_and_the_callback_refires_compensation_on_next_delivery(): void {
    // Simulate the crash: the last attempt's markFailed committed, the process
    // died before onExhausted ran, so there is no terminal marker.
    $this->ledger->markFailed('charge', self::EVENT_ID, 'gateway down', 3);

    $fired = [];
    $this->registry->add($this->stub(
      'charge',
      10,
      body: static function () { throw new \RuntimeException('must not run'); },
      onExhausted: function (IIntegrationEvent $e, \Throwable $last) use (&$fired) {
        $fired[] = [get_class($e), $last->getMessage()];
      }
    ));

    $outcome = $this->delivery(budget: 3)->deliver(OrderPlaced::class, $this->wrapped());

    self::assertSame([], $this->ran, 'the handler is not re-run');
    self::assertSame([[OrderPlaced::class, 'gateway down']], $fired, 'compensation re-fires with the recorded last error');
    self::assertSame(['charge'], $outcome->exhausted);
    self::assertTrue($this->ledger->exhausted('charge', self::EVENT_ID));
    self::assertSame(3, $this->ledger->attempts('charge', self::EVENT_ID));

    $this->delivery(budget: 3)->deliver(OrderPlaced::class, $this->wrapped());
    self::assertCount(1, $fired);
  }

  public function test_subscriber_failures_are_logged_with_the_fact_identity(): void {
    $logged = [];
    $this->registry->add($this->stub('bad', 10, body: static function () { throw new \RuntimeException('boom'); }));

    $this->delivery(5, static function (string $m) use (&$logged) { $logged[] = $m; })
      ->deliver(OrderPlaced::class, $this->wrapped());

    self::assertCount(1, $logged);
    self::assertStringContainsString('bad', $logged[0]);
    self::assertStringContainsString(self::EVENT_ID, $logged[0]);
    self::assertStringContainsString('boom', $logged[0]);
  }

  public function test_an_event_with_no_subscribers_is_complete(): void {
    $outcome = $this->delivery()->deliver(OrderPlaced::class, $this->wrapped());

    self::assertTrue($outcome->isComplete());
    self::assertSame([], $outcome->delivered);
  }

  public function test_handler_backoff_follows_section_5_1(): void {
    self::assertSame(30, IntegrationDelivery::backoffSeconds(1));
    self::assertSame(60, IntegrationDelivery::backoffSeconds(2));
    self::assertSame(240, IntegrationDelivery::backoffSeconds(4));
    self::assertSame(3600, IntegrationDelivery::backoffSeconds(12));
    self::assertSame(5, IntegrationDelivery::DEFAULT_BUDGET);
  }

  public function test_a_poison_fact_counts_an_attempt_against_every_pending_subscriber(): void {
    // wave1-notes core minor 1: from_payload() throwing must still advance
    // the per-subscriber budget, or the fact retries forever.
    $this->registry->add($this->stub('a', Subscriber::LISTENER, PoisonFact::class));
    $this->registry->add($this->stub('b', Subscriber::RESUME, PoisonFact::class));

    try {
      $this->delivery(2)->deliver(PoisonFact::class, $this->wrapped(['id' => 1]));
      self::fail('a poison fact still surfaces while budget remains');
    } catch (\UnexpectedValueException) {
    }

    self::assertSame(1, $this->ledger->attempts('a', self::EVENT_ID));
    self::assertSame(1, $this->ledger->attempts('b', self::EVENT_ID));
    self::assertStringContainsString('payload no longer decodes', (string) $this->ledger->lastError('a', self::EVENT_ID));
  }

  public function test_a_poison_fact_stops_once_every_subscriber_reaches_the_budget(): void {
    $this->registry->add($this->stub('a', Subscriber::LISTENER, PoisonFact::class, null, function () {
      $this->ran[] = 'compensated';
    }));
    $delivery = $this->delivery(2);

    try {
      $delivery->deliver(PoisonFact::class, $this->wrapped(['id' => 1]));
    } catch (\UnexpectedValueException) {
    }
    $outcome = $delivery->deliver(PoisonFact::class, $this->wrapped(['id' => 1]));

    self::assertSame(['a'], $outcome->exhausted, 'budget bounds the poison fact');
    self::assertFalse($outcome->needsRetry());
    self::assertTrue($this->ledger->exhausted('a', self::EVENT_ID));
    self::assertSame([], $this->ran, 'no handler and no compensation: there is no event to give them');

    $again = $delivery->deliver(PoisonFact::class, $this->wrapped(['id' => 1]));
    self::assertSame(['a'], $again->exhausted);
  }

  public function test_a_poison_fact_leaves_delivered_subscribers_alone(): void {
    $this->registry->add($this->stub('done', Subscriber::LISTENER, PoisonFact::class));
    $this->registry->add($this->stub('pending', Subscriber::RESUME, PoisonFact::class));
    $this->ledger->markDelivered('done', self::EVENT_ID);

    try {
      $this->delivery(5)->deliver(PoisonFact::class, $this->wrapped(['id' => 1]));
    } catch (\UnexpectedValueException) {
    }

    self::assertSame(0, $this->ledger->attempts('done', self::EVENT_ID));
    self::assertSame(1, $this->ledger->attempts('pending', self::EVENT_ID));
  }
}
