<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Retries;

/**
 * The wp delivery budget of a DDD subscriber (wave 5 coordinator decision):
 * a listener gets ONE attempt unless its consumer or the listener opts in;
 * process ignition and resume subscribers keep the core budget. The option
 * path needs real get_option and is covered by the v8 integration suite
 * (WpDeliveryBudgetV8Test).
 */
final class WpDeliveryBudgetTest extends TestCase {

  protected function setUp(): void {
    WpLedgeredDelivery::reset_for_tests();
    unset($GLOBALS['_test_filters'][WpLedgeredDelivery::ATTEMPTS_FILTER]);
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::reset_for_tests();
    unset($GLOBALS['_test_filters'][WpLedgeredDelivery::ATTEMPTS_FILTER]);
  }

  public function test_a_listener_gets_one_attempt_by_default(): void {
    self::assertSame(1, WpLedgeredDelivery::LISTENER_ATTEMPTS);
    self::assertSame(1, WpLedgeredDelivery::budget('acme', 'listener:Acme\\Listener'));
    self::assertSame(1, WpLedgeredDelivery::budget('acme', 'action:Closure@plugin.php:12'));
    self::assertSame(1, WpLedgeredDelivery::budget('acme', 'acme/custom-subscriber'));
  }

  public function test_ignition_and_resume_keep_the_core_budget(): void {
    add_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, static fn (): int => 2, 10, 3);

    foreach ([
      'ignition:Acme\\Fulfilment@Acme\\OrderPlaced',
      'resume:Acme\\PaymentTaken',
      'acme/ignition:Acme\\Fulfilment@Acme\\OrderPlaced',
      'acme/resume:Acme\\PaymentTaken',
      'acme/workflow-ignition:Acme\\Digest@Acme\\CronDue',
    ] as $id) {
      self::assertSame(IntegrationDelivery::DEFAULT_BUDGET, WpLedgeredDelivery::budget('acme', $id), $id);
    }
  }

  public function test_the_filter_sets_the_attempts_per_consumer_and_subscriber(): void {
    $seen = [];
    add_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, static function (int $attempts, string $subscriber, string $prefix) use (&$seen): int {
      $seen[] = [$attempts, $subscriber, $prefix];
      return $prefix === 'acme' ? 4 : $attempts;
    }, 10, 3);

    self::assertSame(4, WpLedgeredDelivery::budget('acme', 'listener:Acme\\Listener'));
    self::assertSame(1, WpLedgeredDelivery::budget('other', 'listener:Other\\Listener'));
    self::assertSame([[1, 'listener:Acme\\Listener', 'acme'], [1, 'listener:Other\\Listener', 'other']], $seen);
  }

  public function test_a_filter_cannot_take_a_listener_below_one_attempt(): void {
    add_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, static fn (): mixed => 0, 10, 3);
    self::assertSame(1, WpLedgeredDelivery::budget('acme', 'listener:Acme\\Listener'));

    $GLOBALS['_test_filters'][WpLedgeredDelivery::ATTEMPTS_FILTER] = [static fn (): mixed => 'nonsense'];
    self::assertSame(1, WpLedgeredDelivery::budget('acme', 'listener:Acme\\Listener'));
  }

  public function test_a_bound_listener_with_retries_gets_them(): void {
    $hook = FakeIntegrationEvent::integration_action();
    WpLedgeredDelivery::bind($hook, FakeIntegrationEvent::class, 'listener:Acme\\Patient', 10, static function (): void {}, null, (new Retries(3))->attempts());

    self::assertSame(4, WpLedgeredDelivery::budget('test', 'listener:Acme\\Patient'), '#[Retries(3)]: the first attempt and three retries');
    self::assertSame(1, WpLedgeredDelivery::budget('test', 'listener:Acme\\Other'));
  }

  public function test_the_filter_sees_the_declared_attempts_last(): void {
    $hook = FakeIntegrationEvent::integration_action();
    WpLedgeredDelivery::bind($hook, FakeIntegrationEvent::class, 'listener:Acme\\Patient', 10, static function (): void {}, null, 4);
    add_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, static fn (int $attempts): int => $attempts + 1, 10, 3);

    self::assertSame(5, WpLedgeredDelivery::budget('test', 'listener:Acme\\Patient'));
  }

  public function test_retries_is_read_off_a_class_a_method_a_closure_and_an_invokable(): void {
    self::assertSame(2, Retries::of(RetryingListener::class)?->count);
    self::assertSame(3, Retries::of(RetryingListener::class)?->attempts());
    self::assertSame(2, Retries::of(new RetryingListener())?->count, 'an object: its class');
    self::assertSame(4, Retries::of([new RetryingListener(), 'on_fact'])?->count, 'a method: its own attribute first');
    self::assertSame(2, Retries::of([new RetryingListener(), 'plain'])?->count, 'then its class');
    self::assertSame(1, Retries::of(#[Retries(1)] static function (): void {})?->count);
    self::assertNull(Retries::of(static function (): void {}));
    self::assertNull(Retries::of(PlainListener::class));
    self::assertNull(Retries::of('strlen'));
    self::assertNull(Retries::of('No\\Such\\Listener'));
  }

  public function test_retries_rejects_a_negative_count(): void {
    $this->expectException(\InvalidArgumentException::class);
    new Retries(-1);
  }
}

#[Retries(2)]
final class RetryingListener {

  #[Retries(4)]
  public function on_fact(): void {}

  public function plain(): void {}

  public function __invoke(): void {}
}

final class PlainListener {}
