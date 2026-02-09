<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeUniqueIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeDelayedIntegrationEvent;

class DomainEventTest extends TestCase {

  // ─────────────────────────────────────────────────────────────
  // DomainEvent
  // ─────────────────────────────────────────────────────────────

  public function test_name_derived_from_class_name(): void {
    // FakeDomainEvent -> fake_domain_event
    $this->assertSame('fake_domain_event', FakeDomainEvent::name());
  }

  public function test_action_includes_prefix_and_domain(): void {
    // prefix is 'test', so action = test_domain_fake_domain_event
    $this->assertSame('test_domain_fake_domain_event', FakeDomainEvent::action());
  }

  public function test_payload_returns_event_data(): void {
    $event = new FakeDomainEvent(entity_id: 42, action_type: 'updated');
    $this->assertSame(['entity_id' => 42, 'action_type' => 'updated'], $event->payload());
  }

  public function test_payload_defaults(): void {
    $event = new FakeDomainEvent();
    $this->assertSame(['entity_id' => 1, 'action_type' => 'created'], $event->payload());
  }

  // ─────────────────────────────────────────────────────────────
  // IntegrationEvent
  // ─────────────────────────────────────────────────────────────

  public function test_integration_action_includes_prefix(): void {
    $this->assertSame('test_integration_fake_integration_event', FakeIntegrationEvent::integration_action());
  }

  public function test_integration_payload_scalarises(): void {
    $event = new FakeIntegrationEvent(entity_id: 7, action_type: 'synced');
    $payload = $event->integration_payload();

    $this->assertSame(7, $payload['entity_id']);
    $this->assertSame('synced', $payload['action_type']);
  }

  public function test_default_delay_is_zero(): void {
    $event = new FakeIntegrationEvent();
    $this->assertSame(0, $event->delay());
  }

  public function test_default_is_unique_false(): void {
    $event = new FakeIntegrationEvent();
    $this->assertFalse($event->is_unique());
  }

  public function test_unique_event_returns_true(): void {
    $event = new FakeUniqueIntegrationEvent();
    $this->assertTrue($event->is_unique());
  }

  public function test_delayed_event_returns_delay(): void {
    $event = new FakeDelayedIntegrationEvent();
    $this->assertSame(60, $event->delay());
  }

  public function test_from_payload_reconstructs_event(): void {
    $original = new FakeIntegrationEvent(entity_id: 99, action_type: 'deleted');
    $reconstructed = FakeIntegrationEvent::from_payload($original->payload());

    $this->assertSame(99, $reconstructed->entity_id);
    $this->assertSame('deleted', $reconstructed->action_type);
  }

  // ─────────────────────────────────────────────────────────────
  // Scalarise
  // ─────────────────────────────────────────────────────────────

  public function test_scalarise_null(): void {
    $this->assertNull(FakeIntegrationEvent::scalarise(null));
  }

  public function test_scalarise_scalar(): void {
    $this->assertSame(42, FakeIntegrationEvent::scalarise(42));
    $this->assertSame('hello', FakeIntegrationEvent::scalarise('hello'));
    $this->assertTrue(FakeIntegrationEvent::scalarise(true));
  }

  public function test_scalarise_datetime(): void {
    $dt = new \DateTimeImmutable('2025-06-15T10:30:00+00:00');
    $this->assertSame('2025-06-15T10:30:00+00:00', FakeIntegrationEvent::scalarise($dt));
  }

  public function test_scalarise_backed_enum(): void {
    // Use WorkItemStatus as a convenient backed enum
    $status = \TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus::done;
    $this->assertSame('done', FakeIntegrationEvent::scalarise($status));
  }

  public function test_scalarise_nested_array(): void {
    $result = FakeIntegrationEvent::scalarise(['a' => 1, 'b' => ['c' => 2]]);
    $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $result);
  }

  // ─────────────────────────────────────────────────────────────
  // Name derivation edge cases
  // ─────────────────────────────────────────────────────────────

  public function test_unique_event_name_derived_from_subclass(): void {
    // FakeUniqueIntegrationEvent -> fake_unique_integration_event
    $this->assertSame('fake_unique_integration_event', FakeUniqueIntegrationEvent::name());
  }

  public function test_delayed_event_name(): void {
    $this->assertSame('fake_delayed_integration_event', FakeDelayedIntegrationEvent::name());
  }
}
