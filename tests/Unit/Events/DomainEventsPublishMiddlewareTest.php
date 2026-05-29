<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;

class DomainEventsPublishMiddlewareTest extends TestCase {

  private EventsUnitOfWork $uow;
  private array $routed = [];
  private DomainEventsPublishMiddleware $middleware;

  protected function setUp(): void {
    $this->uow = new EventsUnitOfWork();
    $this->routed = [];

    $dispatcher = $this->createMock(IDomainEventDispatcher::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (IDomainEvent $e) {
      $this->routed[] = $e;
    });
    $bus = $this->createMock(IIntegrationEventBus::class);

    $router = new EventRouter($dispatcher, $bus);
    $this->middleware = new DomainEventsPublishMiddleware($this->uow, $router);
  }

  public function test_resets_uow_before_handler_execution(): void {
    // Pre-seed some events
    $this->uow->record(new FakeDomainEvent(99));

    $this->middleware->execute(new \stdClass(), function () {
      // Handler doesn't record anything — the pre-seeded event should be gone
      return 'result';
    });

    // The stale event should NOT have been published (reset cleared it)
    // Only events recorded during handler execution should be published
    $this->assertEmpty($this->routed);
  }

  public function test_publishes_events_recorded_during_handler(): void {
    $event1 = new FakeDomainEvent(1);
    $event2 = new FakeDomainEvent(2);

    $this->middleware->execute(new \stdClass(), function () use ($event1, $event2) {
      $this->uow->record($event1);
      $this->uow->record($event2);
      return 'result';
    });

    $this->assertCount(2, $this->routed);
    $this->assertSame($event1, $this->routed[0]);
    $this->assertSame($event2, $this->routed[1]);
  }

  public function test_returns_handler_result(): void {
    $result = $this->middleware->execute(new \stdClass(), fn() => 'my_result');
    $this->assertSame('my_result', $result);
  }

  public function test_drains_integration_events_recorded_during_dispatch(): void {
    // Simulate a sync handler that records a further (integration) event while
    // the first event is being published — the transitive event must also flush.
    $first = new FakeDomainEvent(1);
    $cascaded = new FakeIntegrationEvent(2);

    $dispatcher = $this->createMock(IDomainEventDispatcher::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (IDomainEvent $e) use ($first, $cascaded) {
      $this->routed[] = $e;
      if ($e === $first) {
        // A handler fires here and writes an aggregate emitting an integration event.
        $this->uow->record($cascaded);
      }
    });
    $bus = $this->createMock(IIntegrationEventBus::class);
    $middleware = new DomainEventsPublishMiddleware($this->uow, new EventRouter($dispatcher, $bus));

    $middleware->execute(new \stdClass(), function () use ($first) {
      $this->uow->record($first);
    });

    $this->assertCount(2, $this->routed);
    $this->assertSame($first, $this->routed[0]);
    $this->assertSame($cascaded, $this->routed[1]);
  }

  public function test_events_not_published_when_handler_throws(): void {
    $this->expectException(\RuntimeException::class);

    $this->middleware->execute(new \stdClass(), function () {
      $this->uow->record(new FakeDomainEvent());
      throw new \RuntimeException('handler failed');
    });

    // Events should not have been routed (exception thrown before drain)
    $this->assertEmpty($this->routed);
  }
}
