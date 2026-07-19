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
 * The act's footprint at rest (spec appendix 9, built as the touches lane):
 * finalise enriches the EXISTING events JSON entries with their touches
 * (provenance — the declaration stays attached to the fact that made it)
 * and writes one row per touch to {prefix}_touches, minting the version
 * via the unique key + retry (never a naked MAX()+1).
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

  public function test_touch_rows_are_written_with_minted_versions(): void {
    $this->run_act($this->make_bracket());

    $rows = $this->touch_rows();
    $this->assertCount(2, $rows);

    [$table, $license] = $rows[0];
    $this->assertMatchesRegularExpression('/^wp_acmeact\d+_touches$/', $table);
    $this->assertSame('acme.state_license', $license['aggregate']);
    $this->assertSame('4021', $license['aggregate_id']);
    $this->assertSame('created', $license['op']);
    $this->assertSame(1, $license['version'], 'fresh aggregate: MAX is 0, first version is 1');
    $this->assertSame(LicenseIssued::name(), $license['event_name']);
    $this->assertNotEmpty($license['command_id'], 'joins back to the act record');
    $this->assertNotEmpty($license['correlation_id'], 'joins into the story');

    [, $roster] = $rows[1];
    $this->assertSame('acme.roster', $roster['aggregate']);
    $this->assertSame('7', $roster['aggregate_id']);
  }

  public function test_version_collision_retries_with_a_fresh_max(): void {
    $this->max_version = 6;
    $this->refuse_inserts = 1;   // first attempt hits the unique key

    $this->run_act($this->make_bracket());

    $rows = $this->touch_rows();
    $this->assertCount(2, $rows, 'the refused insert was retried, not dropped');
    $this->assertSame(7, $rows[0][1]['version'], 'version re-minted from a fresh MAX read');
  }

  public function test_audit_disabled_writes_no_touches(): void {
    $this->run_act($this->make_bracket(audit_enabled: false));

    $this->assertSame([], $this->touch_rows(), 'no act row to join — no touches rows either');
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
