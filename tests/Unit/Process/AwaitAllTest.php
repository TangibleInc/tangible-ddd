<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitAllTest extends TestCase {

  private function mechanism(array $expected = [1, 2, 3]): AwaitAll {
    return new AwaitAll(
      event_class: FakeResolvedEvent::class,
      expected: $expected,
      key_by: [FakeGatherProcess::class, 'resolution_key'],
      timeout_seconds: 3600,
    );
  }

  private function event(int $id): FakeResolvedEvent {
    return new FakeResolvedEvent($id, FakeOutcome::Accepted, new \DateTimeImmutable());
  }

  public function test_accepts_only_expected_keys(): void {
    $m = $this->mechanism();
    $this->assertTrue($m->accepts($this->event(2)));
    $this->assertFalse($m->accepts($this->event(99)));
  }

  public function test_accumulate_until_satisfied(): void {
    $m = $this->mechanism([1, 2]);
    $m = $m->accumulate($this->event(1));
    $this->assertFalse($m->is_satisfied());
    $this->assertSame([2], $m->missing());
    $m = $m->accumulate($this->event(2));
    $this->assertTrue($m->is_satisfied());
  }

  public function test_duplicate_redelivery_is_idempotent(): void {
    $m = $this->mechanism([1, 2])->accumulate($this->event(1));
    $this->assertFalse($m->accepts($this->event(1)));   // already gathered
    $this->assertFalse($m->accumulate($this->event(1))->is_satisfied());
  }

  public function test_resume_argument_is_the_mechanism(): void {
    $m = $this->mechanism();
    $this->assertSame($m, $m->resume_argument($this->event(1)));
  }

  public function test_timeout_is_required_positive(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AwaitAll(FakeResolvedEvent::class, [1], [FakeGatherProcess::class, 'resolution_key'], timeout_seconds: 0);
  }

  public function test_persistence_round_trip_preserves_gathered(): void {
    $m = $this->mechanism([1, 2])->accumulate($this->event(1));
    $back = AwaitAll::from_array($m->to_array());
    $this->assertSame([1], $back->gathered());
    $this->assertFalse($back->accepts($this->event(1)));
    $this->assertTrue($back->accepts($this->event(2)));
  }

  public function test_from_array_rejects_stale_extractor(): void {
    $data = $this->mechanism()->to_array();
    $data['key_by'] = ['NoSuchClass', 'nope'];
    $this->expectException(\InvalidArgumentException::class);
    AwaitAll::from_array($data);
  }
}
