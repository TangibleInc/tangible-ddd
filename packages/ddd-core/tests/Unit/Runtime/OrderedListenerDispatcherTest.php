<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Runtime\OrderedListenerDispatcher;

interface ShopMoment {}

final class CartCheckedOut extends DomainEvent implements ShopMoment {
  public function __construct(public readonly int $cart_id = 1) {}
  protected static function prefix(): string { return 'acme'; }
  public function payload(): array { return ['cart_id' => $this->cart_id]; }
}

final class CartAbandoned extends DomainEvent {
  public function __construct(public readonly int $cart_id = 1) {}
  protected static function prefix(): string { return 'acme'; }
  public function payload(): array { return ['cart_id' => $this->cart_id]; }
}

final class OrderedListenerDispatcherTest extends TestCase {

  protected function tearDown(): void {
    Reactions::reset();
  }

  public function test_dispatches_synchronously_by_priority_then_registration(): void {
    $ran = [];
    $d = new OrderedListenerDispatcher();
    self::assertInstanceOf(IDomainEventDispatcher::class, $d);

    $d->listen(CartCheckedOut::class, function () use (&$ran) { $ran[] = 'late'; }, 20);
    $d->listen(CartCheckedOut::class, function () use (&$ran) { $ran[] = 'a'; });
    $d->listen(ShopMoment::class, function () use (&$ran) { $ran[] = 'marker'; });
    $d->listen(CartAbandoned::class, function () use (&$ran) { $ran[] = 'other'; });

    $d->dispatch(new CartCheckedOut());

    self::assertSame(['a', 'marker', 'late'], $ran);
  }

  public function test_listeners_receive_the_published_instance(): void {
    $got = null;
    $d = new OrderedListenerDispatcher();
    $d->listen(CartCheckedOut::class, function (CartCheckedOut $e) use (&$got) { $got = $e; });

    $event = new CartCheckedOut(5);
    $d->dispatch($event);

    self::assertSame($event, $got);
  }

  public function test_reactions_are_recorded_against_the_published_instance(): void {
    $d = new OrderedListenerDispatcher();
    $d->listen(CartCheckedOut::class, static function () {}, 10, 'Acme\\Reactions\\SendReceipt');

    $event = new CartCheckedOut();
    $d->dispatch($event);

    $rows = Reactions::of($event);
    self::assertCount(1, $rows);
    self::assertSame('Acme\\Reactions\\SendReceipt', $rows[0]['handler']);
    self::assertArrayNotHasKey('error', $rows[0]);
  }

  public function test_the_first_listener_exception_propagates_after_recording_and_closing_the_frame(): void {
    $ran = [];
    $d = new OrderedListenerDispatcher();
    $d->listen(CartCheckedOut::class, static function () { throw new \DomainException('reaction failed'); }, 10, 'Boom');
    $d->listen(CartCheckedOut::class, function () use (&$ran) { $ran[] = 'after'; }, 20);

    $event = new CartCheckedOut();
    try {
      $d->dispatch($event);
      self::fail('expected the listener exception');
    } catch (\DomainException $e) {
      self::assertSame('reaction failed', $e->getMessage());
    }

    self::assertSame([], $ran, 'the command rolls back; later listeners do not run');
    self::assertSame('reaction failed', Reactions::of($event)[0]['error']);

    // The frame was closed: a record outside any dispatch is a no-op.
    Reactions::record('stray', 0);
    self::assertCount(1, Reactions::of($event));
  }

  public function test_default_handler_names(): void {
    $d = new OrderedListenerDispatcher();
    $invokable = new class { public function __invoke(): void {} };
    $d->listen(CartCheckedOut::class, $invokable);
    $d->listen(CartCheckedOut::class, [$this, 'noop']);
    $d->listen(CartCheckedOut::class, static function () {});

    $event = new CartCheckedOut();
    $d->dispatch($event);

    $names = array_column(Reactions::of($event), 'handler');
    self::assertSame(get_class($invokable), $names[0]);
    self::assertSame(self::class . '::noop', $names[1]);
    self::assertSame('Closure', $names[2]);
  }

  public function noop(): void {}
}
