<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;

class EventRouterTest extends TestCase {

  private array $dispatched = [];
  private array $published = [];
  private EventRouter $router;

  protected function setUp(): void {
    $this->dispatched = [];
    $this->published = [];

    $dispatcher = $this->createMock(IDomainEventDispatcher::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (IDomainEvent $e) {
      $this->dispatched[] = $e;
    });

    $bus = $this->createMock(IIntegrationEventBus::class);
    $bus->method('publish')->willReturnCallback(function (IIntegrationEvent $e) {
      $this->published[] = $e;
    });

    $this->router = new EventRouter($dispatcher, $bus);
  }

  public function test_domain_event_dispatched_but_not_published_to_bus(): void {
    $event = new FakeDomainEvent();
    $this->router->publish($event);

    $this->assertCount(1, $this->dispatched);
    $this->assertEmpty($this->published);
  }

  public function test_integration_event_dispatched_and_published_to_bus(): void {
    $event = new FakeIntegrationEvent();
    $this->router->publish($event);

    $this->assertCount(1, $this->dispatched);
    $this->assertCount(1, $this->published);
    $this->assertSame($event, $this->published[0]);
  }

  public function test_multiple_events_routed_correctly(): void {
    $this->router->publish(new FakeDomainEvent(1));
    $this->router->publish(new FakeIntegrationEvent(2));
    $this->router->publish(new FakeDomainEvent(3));

    $this->assertCount(3, $this->dispatched);
    $this->assertCount(1, $this->published);
  }
}
