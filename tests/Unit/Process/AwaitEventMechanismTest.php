<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;

class AwaitEventMechanismTest extends TestCase {

  public function test_implements_mechanism(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $this->assertInstanceOf(IAwaitMechanism::class, $m);
    $this->assertSame(FakeIntegrationEvent::class, $m->event_class());
  }

  public function test_accepts_matches_criteria_strictly(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $this->assertTrue($m->accepts(new FakeIntegrationEvent(entity_id: 42)));
    $this->assertFalse($m->accepts(new FakeIntegrationEvent(entity_id: 43)));
    $this->assertFalse($m->accepts(new FakeIntegrationEvent()));   // 1 !== 42
  }

  public function test_one_of_one_semantics(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class);
    $e = new FakeIntegrationEvent(entity_id: 42);
    $this->assertTrue($m->accumulate($e)->is_satisfied());
    $this->assertSame($e, $m->resume_argument($e));
    $this->assertSame(0, $m->timeout_seconds());
  }

  public function test_persistence_round_trip(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $back = AwaitEvent::from_array($m->to_array());
    $this->assertSame($m->event_class(), $back->event_class());
    $this->assertTrue($back->accepts(new FakeIntegrationEvent(entity_id: 42)));
  }
}
