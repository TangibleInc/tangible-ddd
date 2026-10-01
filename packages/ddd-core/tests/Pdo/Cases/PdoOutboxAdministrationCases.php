<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoOutboxAdministration;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Outbox\DeadLetter;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxRowIds;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;

abstract class PdoOutboxAdministrationCases extends OutboxTestCase {

  private function admin(?IHostConnection $db = null): PdoOutboxAdministration {
    return new PdoOutboxAdministration($db ?? $this->db, self::PREFIX, $this->clock);
  }

  /** Append, claim and dead-letter one row; returns its DLQ id. */
  private function deadLettered(string $id, string $error = 'boom', ?string $class = null): int {
    $store = $this->store();
    $store->append_fact(self::record($id), $class);
    $claims = array_values(array_filter($store->claim(50, $this->clock->now(), 60), static fn ($c) => $c->event_id === $id));
    $store->dead_letter($claims[0], $error);
    return (int) $this->row('ddd_dlq', 'event_id = ?', [$id])['id'];
  }

  public function test_dead_letters_page_oldest_first(): void {
    $admin = $this->admin();
    self::assertInstanceOf(IOutboxAdministration::class, $admin);
    $a = $this->deadLettered('a', 'first');
    $this->clock->advance('PT1M');
    $b = $this->deadLettered('b', 'second');
    $c = $this->deadLettered('c', 'third');

    $page = $admin->dead_letters(2);
    self::assertSame([$a, $b], array_map(static fn (DeadLetter $d) => $d->dlq_id, $page));
    self::assertSame('a', $page[0]->event_id);
    self::assertSame('first', $page[0]->error);
    self::assertSame(1, $page[0]->attempts);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $page[0]->dead_lettered_at);
    self::assertEquals(self::record('a'), $page[0]->record);

    self::assertSame([$c], array_map(static fn (DeadLetter $d) => $d->dlq_id, $admin->dead_letters(2, (string) $b)));
    self::assertSame([], $admin->dead_letters(0));
  }

  public function test_retry_of_a_dead_lettered_row_resets_it_and_removes_its_dlq_entry(): void {
    $this->deadLettered('e1');
    $this->clock->advance('PT5M');

    $this->admin()->retry('e1');

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('pending', $row['status']);
    self::assertSame(0, (int) $row['attempts']);
    self::assertNull($row['last_error']);
    self::assertSame('2026-10-01 12:05:00.000000', $row['next_attempt_at']);
    self::assertSame(0, $this->countRows('ddd_dlq'), 'sfc-5: a retried dead letter leaves the DLQ');
    self::assertSame(0, $this->admin()->stats()['dead_letters']);
    self::assertCount(1, $this->store()->claim(5, $this->clock->now(), 60));
  }

  public function test_retry_guards_status_and_lease(): void {
    $store = $this->store();
    $admin = $this->admin();

    try {
      $admin->retry('missing');
      self::fail('expected OutboxRowNotFound');
    } catch (OutboxRowNotFound) {
    }

    $store->append(self::record('leased'));
    $store->claim(1, $this->clock->now(), 60);
    foreach ([false, true] as $force) {
      try {
        $admin->retry('leased', $force);
        self::fail('a leased row is always refused');
      } catch (OutboxAdministrationRefused) {
      }
    }

    $store->append(self::record('done'));
    [$c] = $store->claim(1, $this->clock->now(), 60);
    $store->accept($c, 'job:1');
    try {
      $admin->retry('done');
      self::fail('an accepted row needs force (O5)');
    } catch (OutboxAdministrationRefused) {
    }
    $admin->retry('done', true);
    self::assertSame('pending', $this->row('ddd_outbox', 'event_id = ?', ['done'])['status']);
    self::assertNull($this->row('ddd_outbox', 'event_id = ?', ['done'])['transport_ref']);
  }

  public function test_replay_keeps_the_event_id_resets_the_row_and_deletes_the_dlq_row(): void {
    $dlqId = $this->deadLettered('e1');

    $this->admin()->replay($dlqId);

    self::assertSame(1, $this->countRows('ddd_outbox'));
    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('pending', $row['status']);
    self::assertSame(0, (int) $row['attempts']);
    self::assertSame(0, $this->countRows('ddd_dlq'));
    [$claim] = $this->store()->claim(1, $this->clock->now(), 60);
    self::assertSame('e1', $claim->event_id);
  }

  public function test_replay_re_inserts_a_purged_original_with_the_same_event_id_and_class(): void {
    $dlqId = $this->deadLettered('e1', class: 'App\\OrderPlaced');
    $this->db->execute('DELETE FROM tp_ddd_outbox WHERE event_id = ?', ['e1']);

    $this->admin()->replay($dlqId);

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('pending', $row['status']);
    self::assertSame('App\\OrderPlaced', $row['event_class']);
    self::assertSame('2026-10-01 12:00:00.000000', $row['due_at']);
    [$claim] = $this->store()->claim(1, $this->clock->now(), 60);
    self::assertEquals(self::record('e1'), $claim->record);
  }

  public function test_replay_is_one_transaction(): void {
    $dlqId = $this->deadLettered('e1');
    $faulty = new FaultyConnection($this->db);
    $faulty->failStatement = '/DELETE FROM `tp_ddd_dlq`/';

    try {
      $this->admin($faulty)->replay($dlqId);
      self::fail('expected the injected failure');
    } catch (\RuntimeException) {
    }
    self::assertSame('dlq', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
    self::assertSame(1, $this->countRows('ddd_dlq'));
  }

  public function test_repairs_join_the_repair_commands_transaction(): void {
    $dlqId = $this->deadLettered('e1');
    $boundary = new PdoTransactionBoundary($this->db);

    try {
      $boundary->run(function () use ($dlqId) {
        $this->admin()->replay($dlqId);
        throw new \DomainException('repair command failed later');
      });
    } catch (\DomainException) {
    }
    self::assertSame('dlq', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
    self::assertSame(1, $this->countRows('ddd_dlq'));
  }

  public function test_replay_and_discard_of_an_unknown_dlq_id_throw(): void {
    foreach (['replay', 'discard'] as $op) {
      try {
        $this->admin()->$op(424242);
        self::fail("expected OutboxRowNotFound from $op");
      } catch (OutboxRowNotFound) {
      }
    }
  }

  public function test_discard_deletes_the_dlq_row_only(): void {
    $dlqId = $this->deadLettered('e1');

    $this->admin()->discard($dlqId);

    self::assertSame(0, $this->countRows('ddd_dlq'));
    self::assertSame('dlq', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
  }

  public function test_purge_deletes_accepted_rows_accepted_before_the_cutoff(): void {
    $store = $this->store();
    $store->append(self::record('old'));
    $store->append(self::record('pending'));
    [$c] = $store->claim(1, $this->clock->now(), 60);
    $store->accept($c, 'job:1');
    $this->clock->advance('PT2H');
    $store->append(self::record('new', '2026-10-01 14:00:00'));
    [, $new] = $store->claim(5, $this->clock->now(), 60);
    $store->accept($new, 'job:2');
    $this->deadLettered('dead');

    self::assertSame(1, $this->admin()->purge(self::utc('2026-10-01 13:00:00')));
    self::assertNull($this->row('ddd_outbox', 'event_id = ?', ['old']));
    self::assertNotNull($this->row('ddd_outbox', 'event_id = ?', ['new']));
    self::assertNotNull($this->row('ddd_outbox', 'event_id = ?', ['pending']));
    self::assertNotNull($this->row('ddd_outbox', 'event_id = ?', ['dead']));
  }

  public function test_stats_count_by_port_status_plus_dead_letters(): void {
    $store = $this->store();
    $store->append(self::record('p'));
    $this->deadLettered('d');
    $store->append(self::record('u1', extra: ['is_unique' => true, 'payload_signature' => ['x' => 1]]));
    $store->append(self::record('u2', extra: ['is_unique' => true, 'payload_signature' => ['x' => 1]]));

    self::assertSame(
      ['pending' => 2, 'accepted' => 0, 'dlq' => 1, 'cancelled' => 1, 'dead_letters' => 1],
      $this->admin()->stats()
    );
  }

  public function test_integer_row_ids_map_to_event_ids(): void {
    $admin = $this->admin();
    self::assertInstanceOf(IOutboxRowIds::class, $admin);
    $this->store()->append(self::record('e1'));
    $id = (int) $this->row('ddd_outbox', 'event_id = ?', ['e1'])['id'];

    self::assertSame('e1', $admin->event_id_of($id));
    self::assertNull($admin->event_id_of($id + 1000));
  }
}
