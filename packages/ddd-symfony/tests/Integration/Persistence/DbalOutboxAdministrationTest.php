<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

final class DbalOutboxAdministrationTest extends PostgresTestCase {

  private FrozenClock $clock;
  private DbalPostgresOutboxStore $store;
  private DbalOutboxAdministration $admin;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->store = new DbalPostgresOutboxStore($this->db);
    $this->admin = new DbalOutboxAdministration($this->db, $this->clock);
  }

  private function append(string $id): void {
    $this->store->append_fact(new OutboxRecord($id, 'widget_registered', 'txp_integration_widget_registered', 'c', 1, null, ['id' => $id], $this->clock->now()), 'App\\W');
  }

  private function deadLetter(string $id): int {
    $this->append($id);
    [$claim] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($claim, 'boom');
    return (int) $this->db->fetchOne('SELECT id FROM ddd_dlq WHERE event_id = ?', [$id]);
  }

  private function statusOf(string $id): ?string {
    $s = $this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$id]);
    return $s === false ? null : $s;
  }

  public function test_dead_letters_page_oldest_first(): void {
    $a = $this->deadLetter('a');
    $b = $this->deadLetter('b');

    $page = $this->admin->dead_letters(1);
    self::assertCount(1, $page);
    self::assertSame($a, $page[0]->dlq_id);
    self::assertSame('a', $page[0]->event_id);
    self::assertSame('boom', $page[0]->error);
    self::assertSame(1, $page[0]->attempts);
    self::assertSame(['id' => 'a'], $page[0]->record->payload);

    $next = $this->admin->dead_letters(10, (string) $a);
    self::assertSame([$b], array_map(fn ($d) => $d->dlq_id, $next));
  }

  public function test_replay_keeps_the_event_id_resets_the_row_and_deletes_the_dlq_row(): void {
    $dlqId = $this->deadLetter('a');

    $this->admin->replay($dlqId);

    self::assertSame('pending', $this->statusOf('a'));
    self::assertSame(0, (int) $this->db->fetchOne("SELECT attempts FROM ddd_outbox WHERE event_id = 'a'"));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'));
    [$claim] = $this->store->claim(1, $this->clock->now(), 60);
    self::assertSame('a', $claim->event_id);
  }

  public function test_replay_reinserts_a_purged_row_with_the_original_event_id(): void {
    $dlqId = $this->deadLetter('a');
    $this->db->executeStatement("DELETE FROM ddd_outbox WHERE event_id = 'a'");

    $this->admin->replay($dlqId);

    self::assertSame('pending', $this->statusOf('a'));
    self::assertSame('App\\W', $this->store->event_class_of('a'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'));
  }

  public function test_replay_and_discard_of_an_unknown_dlq_id_throw_not_found(): void {
    try {
      $this->admin->replay(999);
      self::fail('replay');
    } catch (OutboxRowNotFound) {
    }
    $this->expectException(OutboxRowNotFound::class);
    $this->admin->discard(999);
  }

  public function test_discard_deletes_only_the_dlq_row(): void {
    $dlqId = $this->deadLetter('a');
    $this->admin->discard($dlqId);
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'));
    self::assertSame('dlq', $this->statusOf('a'));
  }

  public function test_retry_guards_leases_and_status(): void {
    $this->append('leased');
    $this->store->claim(1, $this->clock->now(), 60);
    try {
      $this->admin->retry('leased', force: true);
      self::fail('a leased row is always refused');
    } catch (OutboxAdministrationRefused) {
    }

    $this->append('done');
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c, 'ref');
    try {
      $this->admin->retry('done');
      self::fail('accepted needs force');
    } catch (OutboxAdministrationRefused) {
    }
    $this->deadLetter('dead');
    $this->admin->retry('done', force: true);
    self::assertSame('pending', $this->statusOf('done'));

    $this->admin->retry('dead');
    self::assertSame('pending', $this->statusOf('dead'));

    $this->expectException(OutboxRowNotFound::class);
    $this->admin->retry('nope');
  }

  public function test_retry_of_a_dead_lettered_row_removes_its_dlq_row(): void {
    $this->deadLetter('dead');
    $this->deadLetter('other');

    $this->admin->retry('dead');

    self::assertSame('pending', $this->statusOf('dead'));
    self::assertSame(['other'], array_map(fn ($d) => $d->event_id, $this->admin->dead_letters(10)),
      'the retried row leaves the DLQ; a later replay cannot reset it a second time');
    self::assertSame(1, $this->admin->stats()['dead_letters']);
    self::assertSame(1, $this->admin->stats()['dlq']);
  }

  public function test_purge_deletes_old_accepted_rows_only_and_stats_count_by_status(): void {
    $this->append('old');
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c, 'ref');
    $this->db->executeStatement("UPDATE ddd_outbox SET accepted_at = '2026-09-01T00:00:00Z' WHERE event_id = 'old'");
    $this->append('fresh');
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c, 'ref');
    $this->deadLetter('dead');
    $this->append('waiting');

    self::assertSame(['pending' => 1, 'accepted' => 2, 'dlq' => 1, 'cancelled' => 0, 'dead_letters' => 1], $this->admin->stats());

    self::assertSame(1, $this->admin->purge(new \DateTimeImmutable('2026-09-15T00:00:00Z')));
    self::assertNull($this->statusOf('old'));
    self::assertSame('accepted', $this->statusOf('fresh'));
  }
}
