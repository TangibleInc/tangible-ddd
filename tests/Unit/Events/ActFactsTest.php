<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\ActFacts;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\WordPressEventDispatcher;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeFatMoment;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeOutboxRepository;

/**
 * The facts roster: which integration facts left this act, and which
 * domain moment announced each one. Reactions' sibling for the publish
 * side — same whiteboard idiom (static per-act state, reset semantics),
 * but array-based: facts are noted by VALUE (name + outbox event_id +
 * announcer name), not by instance, because the roster is drained into
 * the act's audit JSON, not looked up per object.
 */
class ActFactsTest extends TestCase {

  protected function setUp(): void {
    ActFacts::reset();
    Correlation::reset();
    global $_test_actions;
    $_test_actions = [];
  }

  protected function tearDown(): void {
    ActFacts::reset();
    Correlation::reset();
  }

  // ── the whiteboard itself ──

  public function test_note_and_drain_round_trip(): void {
    ActFacts::note('license_issued', 'evt-1', 'license_granted');
    ActFacts::note('roster_synced', 'evt-2', null);

    $this->assertSame(
      [
        ['name' => 'license_issued', 'event_id' => 'evt-1', 'announced_by' => 'license_granted'],
        ['name' => 'roster_synced', 'event_id' => 'evt-2', 'announced_by' => null],
      ],
      ActFacts::drain()
    );
  }

  public function test_drain_clears_the_roster(): void {
    ActFacts::note('license_issued', 'evt-1', null);
    ActFacts::drain();

    $this->assertSame([], ActFacts::drain(), 'a drained roster starts the next act empty');
  }

  public function test_reset_clears_roster_and_announcer_stack(): void {
    ActFacts::note('license_issued', 'evt-1', null);
    ActFacts::announce_open('license_granted');
    ActFacts::reset();

    $this->assertSame([], ActFacts::drain());
    $this->assertNull(ActFacts::announcing());
  }

  // ── the announcer frame ──

  public function test_announcing_reflects_the_innermost_open_frame(): void {
    $this->assertNull(ActFacts::announcing(), 'no frame: momentless-port publish');

    ActFacts::announce_open('outer_moment');
    ActFacts::announce_open('inner_moment');
    $this->assertSame('inner_moment', ActFacts::announcing());

    ActFacts::announce_close();
    $this->assertSame('outer_moment', ActFacts::announcing());

    ActFacts::announce_close();
    $this->assertNull(ActFacts::announcing());
  }

  // ── the bus gate: only acts keep a roster ──

  public function test_bus_publish_inside_act_scope_notes_the_fact(): void {
    $bus = $this->make_bus();

    Correlation::within(
      (new TraceContext('story-1'))->for_act('cmd-1'),
      static fn () => $bus->publish(new FakeIntegrationEvent(entity_id: 7))
    );

    $this->assertSame(
      [['name' => 'fake_integration_event', 'event_id' => 'evt_1', 'announced_by' => null]],
      ActFacts::drain(),
      'a direct bus publish inside an act is a momentless-port fact: announced_by null'
    );
  }

  public function test_flat_bus_publish_stays_out_of_the_roster(): void {
    $bus = $this->make_bus();

    $bus->publish(new FakeIntegrationEvent(entity_id: 7));

    $this->assertSame([], ActFacts::drain(), 'flat announces have no act audit to ride');
  }

  public function test_fact_scope_publish_stays_out_of_the_roster(): void {
    // A drain-side listener context (cause = the fact) is not an act;
    // its own commands will keep their own rosters.
    $bus = $this->make_bus();

    Correlation::within(
      (new TraceContext('story-1'))->for_fact('evt-parent'),
      static fn () => $bus->publish(new FakeIntegrationEvent(entity_id: 7))
    );

    $this->assertSame([], ActFacts::drain());
  }

  // ── the router bracket: routed announces carry their announcer ──

  public function test_router_publish_attributes_announced_by_to_the_routing_moment(): void {
    $bus = $this->make_bus();
    $router = new EventRouter(new WordPressEventDispatcher(), $bus);

    $moment = new FakeFatMoment((object) ['id' => 9]);
    Correlation::within(
      (new TraceContext('story-1'))->for_act('cmd-1'),
      static fn () => $router->publish($moment)
    );

    $rows = ActFacts::drain();
    $this->assertCount(1, $rows);
    $this->assertSame(FakeFatMoment::name(), $rows[0]['announced_by'], 'the routing moment, not the twin');
    $this->assertSame(\TangibleDDD\Tests\Fakes\FakeTwinEvent::name(), $rows[0]['name'], 'the fact keeps its own name');
  }

  public function test_router_frame_closes_even_when_the_bus_throws(): void {
    $bus = new class implements IIntegrationEventBus {
      public function publish(IIntegrationEvent $event): void {
        throw new \RuntimeException('outbox down');
      }
    };
    $router = new EventRouter(new WordPressEventDispatcher(), $bus);

    try {
      $router->publish(new FakeFatMoment((object) ['id' => 9]));
      $this->fail('bus failures must propagate');
    } catch (\RuntimeException) {
    }

    $this->assertNull(ActFacts::announcing(), 'the announcer frame is popped by finally');
  }

  private function make_bus(): OutboxIntegrationEventBus {
    return new OutboxIntegrationEventBus(new FakeOutboxRepository(), new FakeDDDConfig());
  }
}
