<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeFatMoment;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeTwinEvent;

class EventRouterTest extends TestCase {

  private array $dispatched = [];
  private array $published = [];
  private EventRouter $router;

  protected function setUp(): void {
    $dispatcher = new class($this->dispatched) implements IDomainEventDispatcher {
      public function __construct(private array &$log) {}
      public function dispatch(IDomainEvent $event): void { $this->log[] = $event; }
    };
    $bus = new class($this->published) implements IIntegrationEventBus {
      public function __construct(private array &$log) {}
      public function publish(IIntegrationEvent $event): void { $this->log[] = $event; }
    };
    $this->router = new EventRouter($dispatcher, $bus);
  }

  public function test_plain_domain_event_never_reaches_bus(): void {
    $this->router->publish(new \TangibleDDD\Tests\Fakes\FakeDomainEvent());
    $this->assertCount(1, $this->dispatched);
    $this->assertCount(0, $this->published);
  }

  public function test_self_publisher_hits_both_surfaces_as_same_object(): void {
    $e = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->router->publish($e);
    $this->assertSame([$e], $this->dispatched);
    $this->assertSame([$e], $this->published);   // identity announcement
  }

  public function test_fat_moment_dispatches_itself_and_publishes_its_twin(): void {
    $moment = new FakeFatMoment(entity: (object)['id' => 42]);
    $this->router->publish($moment);
    $this->assertSame([$moment], $this->dispatched);
    $this->assertCount(1, $this->published);
    $this->assertInstanceOf(FakeTwinEvent::class, $this->published[0]);
    $this->assertSame(42, $this->published[0]->entity_id);
  }
}
