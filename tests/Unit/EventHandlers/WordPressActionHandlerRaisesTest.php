<?php

namespace TangibleDDD\Tests\Unit\EventHandlers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\EventHandlers\WordPressActionHandler;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * The act lane reaches domain-event handlers (0.6.4): WordPressActionHandler
 * carries an optional EventsUnitOfWork exactly like WorkflowHandler does, so
 * a synchronous reaction can $this->event() a follow-on fact mid-drain
 * without ctor-wiring the trait plumbing itself (datastream's
 * MatchSubscriptionsOnCapture fan-out is the motivating consumer).
 *
 * Without the UoW the raise must throw, naming the handler — never silently
 * drop a moment.
 */
class WordPressActionHandlerRaisesTest extends TestCase {

  public function test_injected_uow_receives_handler_raises(): void {
    $uow = new EventsUnitOfWork();
    new RaisingReactionHandler($uow);

    do_action(SourceThingHappened::action(), 7);

    $drained = $uow->drain();
    $this->assertCount(1, $drained, 'the mid-handle raise landed in the injected unit of work');
    $this->assertInstanceOf(FollowOnThingRequested::class, $drained[0]);
    $this->assertSame(7, $drained[0]->thing_id);
  }

  public function test_raise_without_uow_throws_naming_the_handler(): void {
    new UnwiredRaisingHandler();

    try {
      do_action(SecondSourceThingHappened::action(), 3);
      $this->fail('a raise without a unit of work must never silently drop the moment');
    } catch (\LogicException $e) {
      $this->assertStringContainsString(UnwiredRaisingHandler::class, $e->getMessage());
    }
  }
}

// ── fixtures ─────────────────────────────────────────────────────────

class SourceThingHappened extends DomainEvent {
  public function __construct(public readonly int $thing_id) {}
  public static function action(): string { return 'tddd_test_source_thing_happened'; }
  public function payload(): array { return [$this->thing_id]; }
}

class SecondSourceThingHappened extends DomainEvent {
  public function __construct(public readonly int $thing_id) {}
  public static function action(): string { return 'tddd_test_second_source_thing_happened'; }
  public function payload(): array { return [$this->thing_id]; }
}

class FollowOnThingRequested extends DomainEvent {
  public function __construct(public readonly int $thing_id) {}
  public static function action(): string { return 'tddd_test_follow_on_thing_requested'; }
  public function payload(): array { return [$this->thing_id]; }
}

class RaisingReactionHandler extends WordPressActionHandler {
  protected function get_event_class(): string { return SourceThingHappened::class; }

  public function handle(IDomainEvent $event): void {
    /** @var SourceThingHappened $event */
    $this->event(new FollowOnThingRequested($event->thing_id));
  }
}

class UnwiredRaisingHandler extends WordPressActionHandler {
  protected function get_event_class(): string { return SecondSourceThingHappened::class; }

  public function handle(IDomainEvent $event): void {
    /** @var SecondSourceThingHappened $event */
    $this->event(new FollowOnThingRequested($event->thing_id));
  }
}
