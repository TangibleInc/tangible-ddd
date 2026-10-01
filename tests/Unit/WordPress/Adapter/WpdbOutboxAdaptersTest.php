<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Adapter\UncheckedWpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;

/** The transitional wp outbox ports on the 0.6 schema. */
final class WpdbOutboxAdaptersTest extends TestCase {

  protected function setUp(): void {
    WpdbTransactionDepth::resetForTests();
  }

  /**
   * A wpdb whose get_row/get_var answer from $rows by the first prepare()
   * argument, and which records every write.
   *
   * @param array<string, object> $rows key => row
   */
  private function db(array $rows = [], mixed $insert = true): \wpdb {
    return new class($rows, $insert) extends \wpdb {
      public array $writes = [];
      public array $queries = [];
      private mixed $arg = null;
      public function __construct(public array $rows, private mixed $insertResult) {}
      public function prepare(string $query, ...$args): string {
        $this->arg = $args[0] ?? null;
        return $query;
      }
      public function get_row(?string $query = null, string $output = 'OBJECT', int $y = 0) {
        return $this->rows[(string) $this->arg] ?? null;
      }
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        if ($query !== null && str_contains($query, 'SELECT event_id')) {
          return isset($this->rows['id:' . $this->arg]) ? $this->rows['id:' . $this->arg]->event_id : null;
        }
        return isset($this->rows[(string) $this->arg]) ? '1' : null;
      }
      public function query(string $query) {
        $this->queries[] = $query;
        return 1;
      }
      public function insert(string $table, array $data, $format = null): bool {
        $this->writes[] = ['insert', $table, $data];
        if ($this->insertResult === false) {
          $this->last_error = 'Duplicate entry';
        }
        return $this->insertResult;
      }
      public function update(string $table, array $data, array $where, $format = null, $where_format = null): bool {
        $this->writes[] = ['update', $table, $data, $where];
        return true;
      }
      public function delete(string $table, array $where, $where_format = null): bool {
        $this->writes[] = ['delete', $table, $where];
        return true;
      }
    };
  }

  public function test_replay_resets_the_original_row_and_keeps_its_event_id(): void {
    $GLOBALS['wpdb'] = $db = $this->db([
      '7' => (object) ['id' => 7, 'event_id' => 'evt-7', 'event_type' => 't', 'integration_action' => 'a', 'payload' => '{}', 'correlation_id' => 'c', 'command_id' => null, 'blog_id' => 1],
      'evt-7' => (object) ['event_id' => 'evt-7'],
    ]);

    (new WpdbOutboxAdministration('acme'))->replay(7);

    self::assertSame('update', $db->writes[0][0]);
    self::assertSame(['event_id' => 'evt-7'], $db->writes[0][3], 'same event_id');
    self::assertSame('pending', $db->writes[0][2]['status']);
    self::assertSame(['delete', 'wp_acme_integration_dlq', ['id' => 7]], $db->writes[1]);
    self::assertSame(['START TRANSACTION', 'COMMIT'], $db->queries, 'one transaction');
  }

  public function test_replay_reinserts_a_purged_row_with_the_original_event_id(): void {
    $GLOBALS['wpdb'] = $db = $this->db([
      '8' => (object) ['id' => 8, 'event_id' => 'evt-8', 'event_type' => 't', 'integration_action' => 'a', 'payload' => '{"x":1}', 'correlation_id' => 'c', 'command_id' => 'cmd', 'blog_id' => 2],
    ]);

    (new WpdbOutboxAdministration('acme'))->replay(8);

    [$op, $table, $row] = $db->writes[0];
    self::assertSame(['insert', 'wp_acme_integration_outbox'], [$op, $table]);
    self::assertSame('evt-8', $row['event_id']);
    self::assertSame('{"x":1}', $row['payload']);
    self::assertSame(2, $row['blog_id']);
  }

  public function test_replay_inside_the_repair_command_transaction_joins_it(): void {
    $GLOBALS['wpdb'] = $db = $this->db([
      '9' => (object) ['id' => 9, 'event_id' => 'evt-9', 'event_type' => 't', 'integration_action' => 'a', 'payload' => '{}', 'correlation_id' => 'c', 'command_id' => null, 'blog_id' => 1],
      'evt-9' => (object) ['event_id' => 'evt-9'],
    ]);

    (new UncheckedWpdbTransactionBoundary($db))->run(static fn () => (new WpdbOutboxAdministration('acme'))->replay(9));

    self::assertSame(['START TRANSACTION', 'COMMIT'], $db->queries, 'no nested START TRANSACTION');
  }

  public function test_an_unknown_dead_letter_is_not_found(): void {
    $GLOBALS['wpdb'] = $this->db();
    $this->expectException(OutboxRowNotFound::class);
    (new WpdbOutboxAdministration('acme'))->replay(404);
  }

  public function test_retry_refuses_a_leased_row_and_a_delivered_row(): void {
    $GLOBALS['wpdb'] = $this->db([
      'evt-leased' => (object) ['event_id' => 'evt-leased', 'status' => 'pending', 'locked_until' => gmdate('Y-m-d H:i:s', time() + 120)],
      'evt-done' => (object) ['event_id' => 'evt-done', 'status' => 'completed', 'locked_until' => null],
    ]);
    $admin = new WpdbOutboxAdministration('acme');

    try {
      $admin->retry('evt-leased', true);
      self::fail('a leased row is never retried, even forced');
    } catch (OutboxAdministrationRefused) {
    }

    $this->expectException(OutboxAdministrationRefused::class);
    $admin->retry('evt-done');
  }

  public function test_retry_resets_a_failed_row(): void {
    $GLOBALS['wpdb'] = $db = $this->db([
      'evt-f' => (object) ['event_id' => 'evt-f', 'status' => 'dlq', 'locked_until' => null],
    ]);

    (new WpdbOutboxAdministration('acme'))->retry('evt-f');

    self::assertSame(0, $db->writes[0][2]['attempts']);
    self::assertNull($db->writes[0][2]['locked_until']);
  }

  public function test_event_id_of_an_integer_row_id(): void {
    $GLOBALS['wpdb'] = $this->db(['id:12' => (object) ['event_id' => 'evt-12']]);
    $admin = new WpdbOutboxAdministration('acme');

    self::assertSame('evt-12', $admin->eventIdOf(12));
    self::assertNull($admin->eventIdOf(13));
  }

  public function test_append_writes_the_absolute_due_time_and_no_relative_delay(): void {
    $GLOBALS['wpdb'] = $db = $this->db();
    $config = new FakeDDDConfig();
    $store = new WpdbOutboxStore(new OutboxRepository($config, new OutboxConfig()), $config);

    $store->append(new OutboxRecord(
      'evt-a', 'order_placed', 'test_integration_order_placed', 'corr', 3, 'cmd-1', ['order_id' => 1],
      new \DateTimeImmutable('2026-10-01 14:30:00', new \DateTimeZone('Europe/Berlin')), max_attempts: 7,
    ));

    [, $table, $row] = $db->writes[0];
    self::assertSame($config->table('integration_outbox'), $table);
    self::assertSame('2026-10-01 12:30:00', $row['scheduled_at'], 'stored as UTC');
    self::assertSame(0, $row['delay_seconds'], 'no relative delay for any publisher to add again (bug 3)');
    self::assertSame('pending', $row['status']);
    self::assertSame(7, $row['max_attempts']);
    self::assertSame(3, $row['sequence']);
    self::assertSame('{"order_id":1}', $row['payload']);
  }

  public function test_a_failed_append_throws(): void {
    $GLOBALS['wpdb'] = $this->db([], false);
    $config = new FakeDDDConfig();

    $this->expectException(OutboxWriteFailed::class);
    (new WpdbOutboxStore(new OutboxRepository($config, new OutboxConfig()), $config))
      ->append(new OutboxRecord('evt-b', 't', 'a', 'c', 1, null, [], new \DateTimeImmutable()));
  }

  public function test_claim_is_refused_inside_a_transaction(): void {
    $GLOBALS['wpdb'] = $db = $this->db();
    $config = new FakeDDDConfig();
    $store = new WpdbOutboxStore(new OutboxRepository($config, new OutboxConfig()), $config);

    $this->expectException(NestedTransactionRejected::class);
    (new UncheckedWpdbTransactionBoundary($db))->run(static fn () => $store->claim(10, new \DateTimeImmutable(), 300));
  }
}
