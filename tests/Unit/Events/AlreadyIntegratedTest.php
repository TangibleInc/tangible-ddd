<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Domain\Events\AlreadyIntegrated;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

/**
 * The re-raise guard, envelope-first edition (0.3 lane 5): facts no longer
 * carry mutable identity slots — "has this instance already crossed?" is
 * tracked by PublishedFacts (a WeakMap the bus marks at publication).
 * Re-recording a published instance throws; a hydrated twin on the drain
 * side is a DIFFERENT instance and records fine (its at-rest identity
 * lives on the outbox row and the envelope, not on the object).
 */
class AlreadyIntegratedTest extends TestCase {

  public function test_fresh_self_publisher_records_fine(): void {
    $uow = new EventsUnitOfWork();
    $uow->record(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
    $this->assertCount(1, $uow->drain());
  }

  public function test_published_instance_is_rejected_on_re_record(): void {
    $e = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable());
    PublishedFacts::mark($e, 'ev-1');   // what the outbox bus does at publication

    $uow = new EventsUnitOfWork();
    $this->expectException(AlreadyIntegrated::class);
    $uow->record($e);
  }

  public function test_hydrated_twin_is_a_fresh_instance_and_records_fine(): void {
    $published = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'));
    PublishedFacts::mark($published, 'ev-1');

    $twin = FakeResolvedEvent::from_payload(
      ['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00'],
    );

    $uow = new EventsUnitOfWork();
    $uow->record($twin);
    $this->assertCount(1, $uow->drain(), 'identity is per-instance, not per-payload');
  }
}
