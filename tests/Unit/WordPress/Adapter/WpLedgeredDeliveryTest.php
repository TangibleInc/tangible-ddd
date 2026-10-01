<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;

/**
 * The pure parts of the wp per-callback invoker: subscriber ids, the hook's
 * owning prefix, and the 0.6 semantics it keeps when no v8 ledger applies
 * (the unit stubs report every consumer as unmigrated).
 */
final class WpLedgeredDeliveryTest extends TestCase {

  protected function setUp(): void {
    WpLedgeredDelivery::reset_for_tests();
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::reset_for_tests();
  }

  public function test_the_owning_prefix_is_read_off_the_hook(): void {
    $hook = FakeIntegrationEvent::integration_action();
    self::assertSame('test', WpLedgeredDelivery::prefix_of($hook, FakeIntegrationEvent::class));
    self::assertNull(WpLedgeredDelivery::prefix_of('other_hook', FakeIntegrationEvent::class));
    self::assertNull(WpLedgeredDelivery::prefix_of($hook, \stdClass::class));
  }

  public function test_subscriber_ids_are_deterministic_and_repeats_are_numbered(): void {
    $hook = 'test_integration_x';
    $closure = static function (): void {};
    $line = (new \ReflectionFunction($closure))->getStartLine();

    $id = WpLedgeredDelivery::subscriber_id($hook, 'action', $closure);
    self::assertStringStartsWith('action:Closure@', $id);
    self::assertStringEndsWith(':' . $line, $id);

    WpLedgeredDelivery::bind($hook, FakeIntegrationEvent::class, $id, 10, $closure);
    self::assertSame("$id#2", WpLedgeredDelivery::subscriber_id($hook, 'action', $closure));
    self::assertSame('action:' . self::class . '::test_an_unbound_callback_is_a_no_op', WpLedgeredDelivery::subscriber_id($hook, 'action', [$this, 'test_an_unbound_callback_is_a_no_op']));
    self::assertSame('listener:Acme\\Listener', WpLedgeredDelivery::subscriber_id($hook, 'listener', $closure, 'Acme\\Listener'));
  }

  public function test_without_a_v8_ledger_the_callback_keeps_the_0_6_semantics(): void {
    $seen = [];
    $callback = WpLedgeredDelivery::bind('test_integration_x', FakeIntegrationEvent::class, 'action:a', 10, static function (mixed ...$params) use (&$seen): void {
      $seen[] = $params;
      throw new \RuntimeException('propagates');
    });

    try {
      $callback(['__event_id' => 'e1', '__correlation_id' => 'c1', 'entity_id' => 1]);
      self::fail('0.6: a throw propagates and aborts do_action');
    } catch (\RuntimeException $e) {
      self::assertSame('propagates', $e->getMessage());
    }
    self::assertSame([[['__event_id' => 'e1', '__correlation_id' => 'c1', 'entity_id' => 1]]], $seen);
  }

  public function test_an_unbound_callback_is_a_no_op(): void {
    $callback = WpLedgeredDelivery::bind('test_integration_x', FakeIntegrationEvent::class, 'action:a', 10, static function (): void {
      throw new \LogicException('must not run');
    });
    WpLedgeredDelivery::unbind('test_integration_x');
    $callback(['entity_id' => 1]);
    self::assertSame([], WpLedgeredDelivery::subscribers('test_integration_x'));
  }
}
