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
 * The act side of the touches lane (owner ruling 2026-07-19: NO
 * duplication): the audit row's events JSON is a name roster, nothing
 * more — touches live in the touches table, written by the BUS at
 * publication, joined via command_id when wanted together. The bracket
 * neither writes touch rows nor nests them in JSON, and a stamped event of
 * any species must never fatal the finalise.
 */
class ActFootprintTest extends TestCase {

  /** @var array<int, array{0: string, 1: array}> */
  private array $inserts = [];
  /** @var array<int, array> */
  private array $updates = [];
  private EventsUnitOfWork $events;

  private static int $prefix_seq = 0;

  protected function setUp(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
    ConsumerRegistry::add(new AcmeConfig(), static fn () => new \stdClass());
    $this->inserts = [];
    $this->updates = [];
    $this->events = new EventsUnitOfWork();
  }

  protected function tearDown(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
  }

  private function make_bracket(): CorrelationMiddleware {
    $config = new DDDConfig(prefix: 'acmeact' . ++self::$prefix_seq, namespace_root: 'AcmeAct\\Tests', version: 't');
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('prepare')->willReturnCallback(static fn ($sql) => $sql);
    $wpdb->method('get_var')->willReturnCallback(static fn () => $config->table('command_audit'));
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
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

  private function touch_rows(): array {
    return array_values(array_filter($this->inserts, static fn ($i) => str_ends_with($i[0], '_touches')));
  }

  public function test_events_json_is_a_name_roster_even_for_stamped_facts(): void {
    $bracket = $this->make_bracket();
    $bracket->execute(new \stdClass(), function () {
      $this->events->record(new LicenseIssued(the_license: 4021, roster_id: 7));
      $this->events->drain();
      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame([['name' => LicenseIssued::name()]], $events, 'names only — touches are a JOIN away, not a copy');
  }

  public function test_the_bracket_writes_no_touch_rows(): void {
    // The table is the bus's business (0.5.2); the bracket only records the act.
    $bracket = $this->make_bracket();
    $bracket->execute(new \stdClass(), function () {
      $this->events->record(new LicenseIssued(the_license: 4021, roster_id: 7));
      $this->events->drain();
      return 'ok';
    });

    $this->assertSame([], $this->touch_rows());
  }

  public function test_stamped_plain_domain_event_never_fatals_the_finalise(): void {
    // Not a fact: never passes the bus, gets no rows, and must not explode
    // the finally (which would mask the handler's real outcome).
    $bracket = $this->make_bracket();
    $result = $bracket->execute(new \stdClass(), function () {
      $this->events->record(new PlainTouchingEvent(roster_id: 5));
      $this->events->drain();
      return 'ok';
    });

    $this->assertSame('ok', $result);
    $this->assertSame([], $this->touch_rows(), 'not a fact — no rows anywhere');
  }
}

#[\TangibleDDD\Domain\Events\Touches(\TangibleDDD\Domain\Events\Op::Updated, \TangibleDDD\Tests\Fakes\Acme\Domain\Roster::class)]
class PlainTouchingEvent extends \TangibleDDD\Domain\Events\DomainEvent {
  public function __construct(public readonly int $roster_id) {}
  public static function action(): string { return 'acme_plain_touching'; }
  public function payload(): array { return ['roster_id' => $this->roster_id]; }
}
