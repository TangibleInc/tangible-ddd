<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;

class AwaitEventTest extends TestCase {

  public function test_accepts_integration_event_class(): void {
    $await = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);

    $this->assertSame(FakeIntegrationEvent::class, $await->event_class);
    $this->assertSame(['entity_id' => 42], $await->match_criteria);
  }

  public function test_empty_match_criteria_by_default(): void {
    $await = new AwaitEvent(FakeIntegrationEvent::class);
    $this->assertEmpty($await->match_criteria);
  }

  public function test_rejects_non_integration_event(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('IIntegrationEvent');

    // FakeDomainEvent implements IDomainEvent but not IIntegrationEvent
    new AwaitEvent(FakeDomainEvent::class);
  }

  public function test_rejects_arbitrary_class(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AwaitEvent(\stdClass::class);
  }
}
