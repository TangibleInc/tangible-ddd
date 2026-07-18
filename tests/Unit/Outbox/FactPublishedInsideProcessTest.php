<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\FactPublishedInsideProcess;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeOutcome;

/**
 * Trajectory→Fact is a forbidden cell (0.2.5): a saga step publishing an
 * integration event directly produces an orphan fact (command_id = null) —
 * the exact blind spot the original DestinationCutoverProcess had with its
 * in-step \$repo->save() calls. Steps SEQUENCE commands; the command's
 * handler is where facts are announced (raiser edge recorded).
 *
 * The saga ground contact stays legal: step → command → handler announces =
 * process frame AND command frame both occupied.
 */
class FactPublishedInsideProcessTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  private function make_event(): FakeResolvedEvent {
    return new FakeResolvedEvent(7, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-19'));
  }

  private function make_bus(): OutboxIntegrationEventBus {
    $outbox = $this->createStub(IOutboxRepository::class);
    $outbox->method('write')->willReturn('evt-new');
    return new OutboxIntegrationEventBus($outbox);
  }

  public function test_publishing_inside_a_bare_process_wake_throws(): void {
    CorrelationContext::mark_process_frame('191');

    try {
      $this->make_bus()->publish($this->make_event());
      $this->fail('a step publishing directly must throw');
    } catch (FactPublishedInsideProcess $e) {
      $this->assertStringContainsString(FakeResolvedEvent::class, $e->getMessage());
      $this->assertStringContainsString('191', $e->getMessage());
      $this->assertStringContainsString('command', $e->getMessage(), 'remediation points at the bus');
    }
  }

  public function test_saga_ground_contact_publish_is_legal(): void {
    // step → command → handler announces: both frames occupied.
    CorrelationContext::mark_process_frame('191');
    CorrelationContext::mark_command_frame('Acme\\SomeCommand');

    $event = $this->make_event();
    $this->make_bus()->publish($event);

    $this->assertSame('evt-new', $event->event_id());
  }

  public function test_flat_and_command_pass_publishing_are_untouched(): void {
    $event = $this->make_event();
    $this->make_bus()->publish($event);      // flat: wp ddd announce lane

    $this->assertSame('evt-new', $event->event_id());
  }
}
