<?php

namespace TangibleDDD\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;

/**
 * The diary fence: pull_events() is the framework's harvest verb — the ONLY
 * caller is EventsUnitOfWork::collect_from(), reached through the sealed
 * repository save(). Consumers that need to clear a diary (reconstitution:
 * loading must not re-raise anything — hydration must not record —
 * there is no way to walk off with the events.
 */
class EventDiaryFenceTest extends TestCase {

  public function test_save_is_final(): void {
    // The fence is compile-level: a subclass overriding save() to skip
    // collect_from (the seal-dodge) must be a fatal, not a code review find.
    $this->assertTrue(
      (new \ReflectionMethod(PersistsAggregatesRepository::class, 'save'))->isFinal()
    );
  }
}
