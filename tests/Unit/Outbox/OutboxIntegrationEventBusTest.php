<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeOutboxRepository;
use TangibleDDD\Tests\Fakes\FakeUniqueIntegrationEvent;

class OutboxIntegrationEventBusTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  public function test_publish_writes_event_to_outbox(): void {
    // The raiser edge comes from the ambient ACT scope (0.3) — a fact's
    // parent is the command whose handler announced it.
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo, new \TangibleDDD\Tests\Fakes\FakeDDDConfig());

    $event = new FakeIntegrationEvent(entity_id: 7, action_type: 'synced');
    Correlation::within(
      (new \TangibleDDD\Application\Correlation\TraceContext('test-corr-123'))->for_act('cmd-456'),
      static fn () => $bus->publish($event),
    );

    $this->assertCount(1, $repo->entries);
    $entry = $repo->entries[0];
    $this->assertSame('fake_integration_event', $entry->event_type);
    $this->assertSame('test-corr-123', $entry->correlation_id);
    $this->assertSame('cmd-456', $entry->command_id);
    $this->assertSame(7, $entry->payload['entity_id']);
  }

  public function test_unique_event_cancels_duplicates(): void {
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo, new \TangibleDDD\Tests\Fakes\FakeDDDConfig());

    $event = new FakeUniqueIntegrationEvent(entity_id: 1);
    $bus->publish($event);

    $this->assertSame(1, $repo->duplicates_cancelled);
  }

  public function test_non_unique_event_does_not_cancel_duplicates(): void {
    $repo = new FakeOutboxRepository();
    $bus = new OutboxIntegrationEventBus($repo, new \TangibleDDD\Tests\Fakes\FakeDDDConfig());

    $event = new FakeIntegrationEvent(entity_id: 1);
    $bus->publish($event);

    $this->assertSame(0, $repo->duplicates_cancelled);
  }
}
