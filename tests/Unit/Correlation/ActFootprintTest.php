<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Acme\Domain\LicenseIssued;
use TangibleDDD\Tests\Fakes\Acme\Infra\Config as AcmeConfig;

/**
 * The act's footprint at rest (spec appendix 9): finalise enriches the
 * EXISTING events JSON entries with their touches (provenance — the
 * declaration stays attached to the fact that made it). The touches TABLE
 * is the bus's business since 0.5.2 (fact bookkeeping happens where facts
 * happen — see OutboxBusTouchesTest).
 */
class ActFootprintTest extends TestCase {

  /** @var array<int, array{0: string, 1: array}> table => row pairs */
  private array $inserts = [];
  /** @var array<int, array> */
  private array $updates = [];
  /** @var int what MAX(version) answers */
  private int $max_version = 0;
  /** @var int how many touches inserts to refuse (dup-key simulation) */
  private int $refuse_inserts = 0;
  private EventsUnitOfWork $events;

  protected function setUp(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
    ConsumerRegistry::add(new AcmeConfig(), static fn () => new \stdClass());
    $this->inserts = [];
    $this->updates = [];
    $this->max_version = 0;
    $this->refuse_inserts = 0;
    $this->events = new EventsUnitOfWork();
  }

  protected function tearDown(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
  }

  private static int $prefix_seq = 0;

  private function make_bracket(bool $audit_enabled = true): CorrelationMiddleware {
    // Fresh prefix per bracket: command_audit_enabled() caches per prefix
    // (same dodge as ActBracketTest).
    $config = new DDDConfig(prefix: 'acmeact' . ++self::$prefix_seq, namespace_root: 'AcmeAct\\Tests', version: 't');
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('prepare')->willReturnCallback(static fn ($sql) => $sql);
    $wpdb->method('get_var')->willReturnCallback(function ($sql) use ($config, $audit_enabled) {
      if (is_string($sql) && str_contains($sql, 'MAX(version)')) {
        return (string) $this->max_version;
      }
      return $audit_enabled ? $config->table('command_audit') : null;
    });
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
      if (str_ends_with($table, '_touches') && $this->refuse_inserts > 0) {
        $this->refuse_inserts--;
        return false;   // simulated duplicate-key collision
      }
      $this->inserts[] = [$table, $row];
      return true;
    });
    $wpdb->method('update')->willReturnCallback(function ($table, $row) {
      $this->updates[] = $row;
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return new CorrelationMiddleware($config, $this->events, new Redactor());
  }

  /** Run one act whose handler publishes LicenseIssued through the UoW. */
  private function run_act(CorrelationMiddleware $bracket): void {
    $bracket->execute(new \stdClass(), function () {
      $this->events->record(new LicenseIssued(the_license: 4021, roster_id: 7));
      $this->events->drain();
      return 'ok';
    });
  }

  private function touch_rows(): array {
    return array_values(array_filter($this->inserts, static fn ($i) => str_ends_with($i[0], '_touches')));
  }

  public function test_events_json_entries_carry_their_touches(): void {
    $this->run_act($this->make_bracket());

    $this->assertCount(1, $this->updates);
    $events = json_decode($this->updates[0]['events'], true);
    $this->assertCount(1, $events);
    $this->assertSame(LicenseIssued::name(), $events[0]['name']);
    $this->assertSame([
      ['aggregate' => 'acme.state_license', 'id' => '4021', 'op' => 'created'],
      ['aggregate' => 'acme.roster', 'id' => '7', 'op' => 'updated'],
    ], $events[0]['touches'], 'the declaration stays attached to the fact that made it');
  }

  public function test_the_bracket_writes_no_touch_rows(): void {
    // 0.5.2: the table is written by the bus at publication; the bracket's
    // finalise only enriches the audit JSON. (These UoW-recorded events
    // never passed the bus, so no rows anywhere.)
    $this->run_act($this->make_bracket());

    $this->assertSame([], $this->touch_rows());
  }

  public function test_plain_domain_event_with_touches_never_fatals(): void {
    // A stamped NON-integration event must not fatal the finally. Its
    // touches appear in the audit JSON (provenance) — but it is not a
    // fact, never passes the bus, and gets NO biography rows.
    $bracket = $this->make_bracket();
    $bracket->execute(new \stdClass(), function () {
      $this->events->record(new PlainTouchingEvent(roster_id: 5));
      $this->events->drain();
      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame('acme.roster', $events[0]['touches'][0]['aggregate']);
    $this->assertSame([], $this->touch_rows(), 'not a fact — no rows');
  }

  public function test_twin_lane_json_shows_the_announced_records_touches(): void {
    // Twin style: the SOURCE rides the UoW log, the stamps live on the
    // TWIN. The finalise follows the source → record link so the audit
    // JSON's provenance still nests the twin's touches under the source's
    // entry. (Rows are the bus's business — OutboxBusTouchesTest.)
    $roster = new \TangibleDDD\Tests\Fakes\Acme\Domain\Roster(7);
    $source = new \TangibleDDD\Tests\Fakes\Acme\Domain\RosterSynced($roster, added: 3);

    $bracket = $this->make_bracket();
    $bracket->execute(new \stdClass(), function () use ($source) {
      $twin = $source->to_integration();
      \TangibleDDD\Application\Events\PublishedFacts::link_source($source, $twin);
      $this->events->record($source);
      $this->events->drain();
      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame(
      [['aggregate' => 'acme.roster', 'id' => '7', 'op' => 'updated']],
      $events[0]['touches'],
      "the twin's declarations, nested under the source entry"
    );
  }

  public function test_event_router_links_the_twin_to_its_source(): void {
    $roster = new \TangibleDDD\Tests\Fakes\Acme\Domain\Roster(7);
    $source = new \TangibleDDD\Tests\Fakes\Acme\Domain\RosterSynced($roster, added: 3);

    $bus = new class implements \TangibleDDD\Application\Events\IIntegrationEventBus {
      public ?object $published = null;
      public function publish(\TangibleDDD\Domain\Events\IIntegrationEvent $event): void { $this->published = $event; }
    };
    $dispatcher = new class implements \TangibleDDD\Application\Events\IDomainEventDispatcher {
      public function dispatch(\TangibleDDD\Domain\Events\IDomainEvent $event): void {}
    };

    (new \TangibleDDD\Application\Events\EventRouter($dispatcher, $bus))->publish($source);

    $this->assertInstanceOf(\TangibleDDD\Tests\Fakes\Acme\Domain\RosterSyncedTwin::class, $bus->published);
    $this->assertSame(
      $bus->published,
      \TangibleDDD\Application\Events\PublishedFacts::fact_of($source),
      'the router records which twin announced this source'
    );
  }

  public function test_undeclared_events_add_no_touches_key(): void {
    $bracket = $this->make_bracket();
    $bracket->execute(new \stdClass(), function () {
      $this->events->record(new \TangibleDDD\Tests\Fakes\FakeResolvedEvent(1, \TangibleDDD\Tests\Fakes\FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-19')));
      $this->events->drain();
      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertArrayNotHasKey('touches', $events[0], 'lean JSON: no key when nothing declared');
    $this->assertSame([], $this->touch_rows());
  }
}

#[\TangibleDDD\Domain\Events\Touches(\TangibleDDD\Domain\Events\Op::Updated, \TangibleDDD\Tests\Fakes\Acme\Domain\Roster::class)]
class PlainTouchingEvent extends \TangibleDDD\Domain\Events\DomainEvent {
  public function __construct(public readonly int $roster_id) {}
  public static function action(): string { return 'acme_plain_touching'; }
  public function payload(): array { return ['roster_id' => $this->roster_id]; }
}
