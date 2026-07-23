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
 * loading must not re-raise) say discard_events(), which returns nothing —
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

  public function test_discard_events_clears_the_diary_without_returning_it(): void {
    $aggregate = new class(null) extends Aggregate {};
    $aggregate->event(new FakeDomainEvent(1));
    $aggregate->event(new FakeDomainEvent(2));

    $method = new \ReflectionMethod($aggregate, 'discard_events');
    $this->assertSame('void', (string) $method->getReturnType(), 'discard hands nothing back');

    $aggregate->discard_events();

    $this->assertSame([], $aggregate->pull_events(), 'the diary is empty after discard');
  }

  public function test_discard_then_record_keeps_only_the_new_entry(): void {
    // The reconstitution shape: hydrate (raises constructor-time events),
    // discard, then live mutations record normally.
    $aggregate = new class(null) extends Aggregate {};
    $aggregate->event(new FakeDomainEvent(1, 'hydrated'));
    $aggregate->discard_events();

    $live = new FakeDomainEvent(2, 'updated');
    $aggregate->event($live);

    $this->assertSame([$live], $aggregate->pull_events());
  }

  public function test_the_contract_names_discard_events(): void {
    // IRecordsDomainEvents carries the verb so repositories and conformance
    // tooling can rely on it for anything diary-shaped, trait or not.
    $this->assertTrue(
      (new \ReflectionClass(IRecordsDomainEvents::class))->hasMethod('discard_events')
    );
  }
}
