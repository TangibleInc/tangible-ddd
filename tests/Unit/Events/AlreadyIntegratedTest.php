<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\AlreadyIntegrated;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AlreadyIntegratedTest extends TestCase {

  public function test_fresh_self_publisher_records_fine(): void {
    $uow = new EventsUnitOfWork();
    $uow->record(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
    $this->assertCount(1, $uow->drain());
  }

  public function test_stamped_reconstruction_is_rejected(): void {
    $e = FakeResolvedEvent::from_payload(['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00']);
    $e->stamp_journey('corr-1', 'ev-1');   // hydration path stamps — this IS a traveled fact
    $uow = new EventsUnitOfWork();
    $this->expectException(AlreadyIntegrated::class);
    $uow->record($e);
  }
}
