<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeOutboxRepository;
use TangibleDDD\Tests\Fakes\FakeUniqueIntegrationEvent;

class OutboxIntegrationEventBusTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_publish_writes_event_to_outbox(): void {
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo);

    CorrelationContext::init('test-corr-123');
    CorrelationContext::set_command_id('cmd-456');

    $event = new FakeIntegrationEvent(entity_id: 7, action_type: 'synced');
    $bus->publish($event);

    $this->assertCount(1, $repo->entries);
    $entry = $repo->entries[0];
    $this->assertSame('fake_integration_event', $entry->event_type);
    $this->assertSame('test-corr-123', $entry->correlation_id);
    $this->assertSame('cmd-456', $entry->command_id);
    $this->assertSame(7, $entry->payload['entity_id']);
  }

  public function test_unique_event_cancels_duplicates(): void {
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo);

    CorrelationContext::init('corr-1');

    $event = new FakeUniqueIntegrationEvent(entity_id: 1);
    $bus->publish($event);

    $this->assertSame(1, $repo->duplicates_cancelled);
  }

  public function test_non_unique_event_does_not_cancel_duplicates(): void {
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo);

    CorrelationContext::init('corr-1');

    $event = new FakeIntegrationEvent(entity_id: 1);
    $bus->publish($event);

    $this->assertSame(0, $repo->duplicates_cancelled);
  }
}
