<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeOutcome;

class PartitionTest extends TestCase {

  public function test_twin_is_not_a_domain_event(): void {
    $twin = new FakeIntegrationEvent(entity_id: 42);
    $this->assertInstanceOf(IIntegrationEvent::class, $twin);
    $this->assertNotInstanceOf(IDomainEvent::class, $twin);
  }

  public function test_integration_event_base_has_no_domain_hook(): void {
    $this->assertFalse(method_exists(IntegrationEvent::class, 'action'));
    $this->assertFalse(method_exists(IntegrationEvent::class, 'payload'));
  }

  public function test_twin_round_trips_via_trait(): void {
    $twin = new FakeIntegrationEvent(entity_id: 42, action_type: 'synced');
    $back = FakeIntegrationEvent::from_payload($twin->integration_payload());
    $this->assertSame(42, $back->entity_id);
    $this->assertSame('synced', $back->action_type);
  }

  public function test_self_publisher_is_both(): void {
    $e = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertInstanceOf(IDomainEvent::class, $e);
    $this->assertInstanceOf(IIntegrationEvent::class, $e);
  }

  public function test_hook_names_frozen(): void {
    $this->assertSame('test_integration_fake_integration_event', FakeIntegrationEvent::integration_action());
  }
}
