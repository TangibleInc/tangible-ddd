<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\DomainEventAfterSealException;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeFatMoment;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class EventsUnitOfWorkTest extends TestCase {

  private EventsUnitOfWork $uow;

  protected function setUp(): void {
    $this->uow = new EventsUnitOfWork();
  }

  public function test_starts_empty(): void {
    $this->assertEmpty($this->uow->drain());
    $this->assertEmpty($this->uow->published());
  }

  public function test_record_adds_event_to_queue(): void {
    $event = new FakeDomainEvent();
    $this->uow->record($event);

    $drained = $this->uow->drain();
    $this->assertCount(1, $drained);
    $this->assertSame($event, $drained[0]);
  }

  public function test_drain_clears_queue_and_moves_to_published(): void {
    $event1 = new FakeDomainEvent(1);
    $event2 = new FakeDomainEvent(2);

    $this->uow->record($event1);
    $this->uow->record($event2);

    $drained = $this->uow->drain();
    $this->assertCount(2, $drained);

    // Queue is now empty
    $this->assertEmpty($this->uow->drain());

    // Published contains both events
    $this->assertCount(2, $this->uow->published());
  }

  public function test_multiple_drains_accumulate_in_published(): void {
    $this->uow->record(new FakeDomainEvent(1));
    $this->uow->drain();

    $this->uow->record(new FakeDomainEvent(2));
    $this->uow->drain();

    $this->assertCount(2, $this->uow->published());
  }

  public function test_reset_clears_both_queued_and_published(): void {
    $this->uow->record(new FakeDomainEvent(1));
    $this->uow->drain();
    $this->uow->record(new FakeDomainEvent(2));

    $this->uow->reset();

    $this->assertEmpty($this->uow->drain());
    $this->assertEmpty($this->uow->published());
  }

  public function test_collect_from_pulls_events_from_aggregate(): void {
    $aggregate = new class(null) extends \TangibleDDD\Domain\Shared\Aggregate {};
    $event = new FakeDomainEvent(42);
    $aggregate->event($event);

    $this->uow->collect_from($aggregate);

    $drained = $this->uow->drain();
    $this->assertCount(1, $drained);
    $this->assertSame($event, $drained[0]);

    // Aggregate's events are cleared after pull
    $this->assertEmpty($aggregate->pull_events());
  }

  public function test_collect_from_multiple_aggregates(): void {
    $agg1 = new class(null) extends \TangibleDDD\Domain\Shared\Aggregate {};
    $agg2 = new class(null) extends \TangibleDDD\Domain\Shared\Aggregate {};

    $agg1->event(new FakeDomainEvent(1));
    $agg1->event(new FakeDomainEvent(2));
    $agg2->event(new FakeResolvedEvent(3, FakeOutcome::Accepted, new \DateTimeImmutable()));

    $this->uow->collect_from($agg1);
    $this->uow->collect_from($agg2);

    $drained = $this->uow->drain();
    $this->assertCount(3, $drained);
  }

  public function test_sealed_uow_rejects_domain_events(): void {
    $this->uow->seal();

    $this->expectException(DomainEventAfterSealException::class);
    $this->uow->record(new FakeDomainEvent());
  }

  public function test_sealed_uow_accepts_integration_events(): void {
    $this->uow->seal();

    // Must be a self-publisher (also an IDomainEvent) — record() types
    // IDomainEvent, and a pure twin (FakeIntegrationEvent) no longer qualifies.
    $event = new FakeResolvedEvent(7, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->uow->record($event);

    $drained = $this->uow->drain();
    $this->assertCount(1, $drained);
    $this->assertSame($event, $drained[0]);
  }

  public function test_sealed_uow_accepts_twin_style_announcers(): void {
    // The seal exempts INTEGRABLE events — those EventRouter will route to the
    // bus — and that marker is IAnnouncesIntegration, not IIntegrationEvent.
    // A fat domain event that announces a SEPARATE scalar twin
    // (IAnnouncesIntegration, but NOT itself an IIntegrationEvent) must survive
    // the seal: its to_integration() twin reaches the bus. This was wrongly
    // rejected while the seal keyed on IIntegrationEvent (a regression the
    // 0.2.0 taxonomy split introduced — before the split IIntegrationEvent WAS
    // a domain event, so the old condition meant the same thing).
    $this->uow->seal();

    $event = new FakeFatMoment((object) ['id' => 9]);
    $this->uow->record($event);

    $drained = $this->uow->drain();
    $this->assertCount(1, $drained);
    $this->assertSame($event, $drained[0]);
  }

  public function test_unsealed_uow_accepts_domain_events(): void {
    $event = new FakeDomainEvent();
    $this->uow->record($event);

    $this->assertCount(1, $this->uow->drain());
  }

  public function test_reset_unseals(): void {
    $this->uow->seal();
    $this->uow->reset();

    // Should not throw — reset returns to the open phase.
    $this->uow->record(new FakeDomainEvent());
    $this->assertCount(1, $this->uow->drain());
  }

  public function test_collect_from_aggregate_emitting_domain_event_throws_when_sealed(): void {
    $this->uow->seal();
    $aggregate = new class(null) extends \TangibleDDD\Domain\Shared\Aggregate {};
    $aggregate->event(new FakeDomainEvent());

    $this->expectException(DomainEventAfterSealException::class);
    $this->uow->collect_from($aggregate);
  }
}
