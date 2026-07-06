<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Events\NonReversibleValue;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class IntegrationBehaviourTest extends TestCase {

  public function test_integration_payload_is_named_scalars(): void {
    $e = new FakeResolvedEvent(request_id: 312, outcome: FakeOutcome::Accepted, resolved_at: new \DateTimeImmutable('2026-07-06T10:00:00+00:00'));
    $this->assertSame(
      ['request_id' => 312, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00', 'extra' => []],
      $e->integration_payload()
    );
  }

  public function test_round_trip_law(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'), ['a' => 1]);
    $back = FakeResolvedEvent::from_payload($e->integration_payload());
    $this->assertSame($e->integration_payload(), $back->integration_payload());
    $this->assertSame(FakeOutcome::Accepted, $back->outcome);
    $this->assertInstanceOf(\DateTimeImmutable::class, $back->resolved_at);
  }

  public function test_hydrate_ignores_transport_keys(): void {
    $back = FakeResolvedEvent::from_payload([
      'request_id' => 312, 'outcome' => 'accepted',
      'resolved_at' => '2026-07-06T10:00:00+00:00',
      '__correlation_id' => 'abc', '__sequence' => 3, '__event_id' => 'ev-1',
    ]);
    $this->assertSame(312, $back->request_id);
  }

  public function test_scalarise_throws_on_non_reversible_value(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable(), ['bad' => new \stdClass()]);
    $this->expectException(NonReversibleValue::class);
    $e->integration_payload();
  }

  public function test_journey_slots_null_until_stamped_then_readable(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertNull($e->correlation_id());
    $this->assertNull($e->event_id());
    $e->stamp_journey('corr-1', 'ev-1');
    $this->assertSame('corr-1', $e->correlation_id());
    $this->assertSame('ev-1', $e->event_id());
  }

  public function test_journey_slots_never_enter_payload(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'));
    $e->stamp_journey('corr-1', 'ev-1');
    $this->assertArrayNotHasKey('correlation_id', $e->integration_payload());
    $this->assertArrayNotHasKey('event_id', $e->integration_payload());
  }

  public function test_identity_announcement(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertSame($e, $e->to_integration());
  }

  public function test_integration_action_name(): void {
    $this->assertSame('test_integration_fake_resolved_event', FakeResolvedEvent::integration_action());
  }
}
