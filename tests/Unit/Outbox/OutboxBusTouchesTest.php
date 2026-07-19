<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Tests\Fakes\Acme\Domain\Roster;
use TangibleDDD\Tests\Fakes\Acme\Domain\RosterSyncedTwin;
use TangibleDDD\Tests\Fakes\Acme\Infra\Config as AcmeConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * The touches table is the BUS's business (0.5.2): fact bookkeeping happens
 * where facts happen — the fact arrives with its stamps, its identity is
 * minted here, story and raiser are in hand, and EVERY publication lane
 * passes this point (acts, wp ddd announce, flat publishes). Versions mint
 * under the unique key with retry — never a naked MAX()+1.
 */
class OutboxBusTouchesTest extends TestCase {

  /** @var array<int, array{0: string, 1: array}> */
  private array $inserts = [];
  private int $max_version = 0;
  private int $refuse_inserts = 0;

  protected function setUp(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
    ConsumerRegistry::add(new AcmeConfig(), static fn () => new \stdClass());
    $this->inserts = [];
    $this->max_version = 0;
    $this->refuse_inserts = 0;

    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('prepare')->willReturnCallback(static fn ($sql) => $sql);
    $wpdb->method('get_var')->willReturnCallback(function ($sql) {
      return is_string($sql) && str_contains($sql, 'MAX(version)') ? (string) $this->max_version : null;
    });
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
      if ($this->refuse_inserts > 0) {
        $this->refuse_inserts--;
        return false;
      }
      $this->inserts[] = [$table, $row];
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;
  }

  protected function tearDown(): void {
    Correlation::reset();
    ConsumerRegistry::reset();
  }

  private function make_bus(): OutboxIntegrationEventBus {
    $outbox = $this->createStub(IOutboxRepository::class);
    $outbox->method('write')->willReturn('evt-42');
    return new OutboxIntegrationEventBus($outbox, new FakeDDDConfig());
  }

  private function fact(): RosterSyncedTwin {
    return new RosterSyncedTwin(roster_id: 7, added: 3);
  }

  public function test_publishing_a_stamped_fact_writes_touch_rows(): void {
    Correlation::within(Correlation::current()->for_act('cmd-9'), function () {
      $this->make_bus()->publish($this->fact());
    });

    $this->assertCount(1, $this->inserts);
    [$table, $row] = $this->inserts[0];
    $this->assertSame('wp_test_touches', $table);
    $this->assertSame('acme.roster', $row['aggregate']);
    $this->assertSame('7', $row['aggregate_id']);
    $this->assertSame('updated', $row['op']);
    $this->assertSame(1, $row['version'], 'fresh aggregate: first version is 1');
    $this->assertSame(RosterSyncedTwin::name(), $row['event_name'], "the FACT's name — the announced record");
    $this->assertSame('evt-42', $row['event_id'], 'the outbox identity, minted two lines up');
    $this->assertSame('cmd-9', $row['command_id'], 'the raiser act, read off the ambient cause');
    $this->assertNotEmpty($row['correlation_id']);
  }

  public function test_announce_lane_facts_are_indexed_with_no_act(): void {
    // wp ddd announce / flat publishes never pass an act finalise — the
    // 0.5.0 design silently dropped their touches. The bus lane covers them.
    $this->make_bus()->publish($this->fact());

    $this->assertCount(1, $this->inserts);
    $row = $this->inserts[0][1];
    $this->assertNull($row['command_id'], 'no act — a sanctioned command-less door');
    $this->assertNotEmpty($row['correlation_id'], 'the fact still starts its own story');
  }

  public function test_version_collision_retries_with_a_fresh_max(): void {
    $this->max_version = 6;
    $this->refuse_inserts = 1;

    $this->make_bus()->publish($this->fact());

    $this->assertCount(1, $this->inserts, 'the refused insert was retried, not dropped');
    $this->assertSame(7, $this->inserts[0][1]['version'], 're-minted from a fresh MAX read');
  }

  public function test_unstamped_facts_write_nothing(): void {
    $bus = $this->make_bus();
    $event = new \TangibleDDD\Tests\Fakes\FakeResolvedEvent(1, \TangibleDDD\Tests\Fakes\FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-19'));

    $bus->publish($event);

    $this->assertSame([], $this->inserts);
  }
}
