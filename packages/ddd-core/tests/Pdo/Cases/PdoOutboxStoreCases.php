<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\PdoOutboxStore;
use TangibleDDD\Defaults\Pdo\PdoPauseStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Testing\InMemoryRelayPauseStore;

abstract class PdoOutboxStoreCases extends PdoTestCase {

  protected FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
  }

  protected function store(?IHostConnection $db = null, ?IRelayPauseStore $pauses = null): PdoOutboxStore {
    return new PdoOutboxStore($db ?? $this->db, $pauses, self::PREFIX, $this->clock);
  }

  protected static function record(string $id, string $due = '2026-10-01 12:00:00', array $extra = []): OutboxRecord {
    return new OutboxRecord(...$extra + [
      'event_id' => $id,
      'event_type' => 'acme_order_placed',
      'integration_action' => 'acme_integration_order_placed',
      'correlation_id' => 'corr-' . $id,
      'sequence' => 3,
      'command_id' => 'cmd-' . $id,
      'payload' => ['order_id' => 7, 'total' => 12.0, 'note' => "naïve \u{1F600}", 'nested' => ['a' => [1, 2]]],
      'due_at' => self::utc($due),
      'is_unique' => false,
      'payload_signature' => null,
      'max_attempts' => 5,
      'blog_id' => null,
    ]);
  }

  /** @return list<string> */
  private static function ids(array $claims): array {
    return array_map(static fn (Claim $c) => $c->event_id, $claims);
  }

  public function test_append_then_claim_round_trips_the_record(): void {
    $store = $this->store();
    self::assertInstanceOf(IOutboxStore::class, $store);
    $record = self::record('e1', extra: ['blog_id' => 4, 'max_attempts' => 9, 'is_unique' => true, 'payload_signature' => ['b' => 2, 'a' => 1]]);

    $store->append($record);
    [$claim] = $store->claim(10, $this->clock->now(), 60);

    self::assertSame('e1', $claim->event_id);
    self::assertSame(0, $claim->attempts);
    self::assertEquals(self::utc('2026-10-01 12:01:00'), $claim->leaseUntil);
    self::assertEquals($record, $claim->record);
    self::assertSame('UTC', $claim->record->due_at->getTimezone()->getName());
  }

  public function test_append_joins_the_ambient_transaction(): void {
    $store = $this->store();
    $boundary = new PdoTransactionBoundary($this->db);

    try {
      $boundary->run(function () use ($store) {
        $store->append(self::record('e1'));
        throw new \DomainException('reaction failed');
      });
    } catch (\DomainException) {
    }

    self::assertSame(0, $this->countRows('ddd_outbox'));
  }

  public function test_due_at_is_stored_once_as_absolute_utc(): void {
    $due = new \DateTimeImmutable('2026-10-01 08:30:00.250000', new \DateTimeZone('America/New_York'));
    $this->store()->append(self::record('e1', extra: ['due_at' => $due]));

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('2026-10-01 12:30:00.250000', $row['due_at']);
    self::assertSame($row['due_at'], $row['next_attempt_at']);
  }

  public function test_a_duplicate_event_id_throws_outbox_write_failed(): void {
    $store = $this->store();
    $store->append(self::record('e1'));

    $this->expectException(OutboxWriteFailed::class);
    $store->append(self::record('e1'));
  }

  public function test_an_unencodable_payload_throws_outbox_write_failed(): void {
    $this->expectException(OutboxWriteFailed::class);
    $this->store()->append(self::record('e1', extra: ['payload' => ['bad' => "\xB1\x31"]]));
  }

  public function test_is_unique_cancels_only_unleased_pending_rows_with_the_same_signature(): void {
    $store = $this->store();
    $sig = ['user_id' => 1];
    $store->append(self::record('leased', extra: ['is_unique' => true, 'payload_signature' => $sig]));
    $store->claim(1, $this->clock->now(), 60);
    $store->append(self::record('older', extra: ['is_unique' => true, 'payload_signature' => $sig]));
    $store->append(self::record('other-sig', extra: ['is_unique' => true, 'payload_signature' => ['user_id' => 2]]));
    $store->append(self::record('other-type', extra: ['event_type' => 'acme_other', 'is_unique' => true, 'payload_signature' => $sig]));

    $store->append(self::record('newest', extra: ['is_unique' => true, 'payload_signature' => $sig]));

    $status = fn (string $id) => $this->row('ddd_outbox', 'event_id = ?', [$id])['status'];
    self::assertSame('pending', $status('leased'), 'leased rows are never cancelled');
    self::assertSame('cancelled', $status('older'));
    self::assertSame('pending', $status('other-sig'));
    self::assertSame('pending', $status('other-type'));
    self::assertSame('pending', $status('newest'));
  }

  public function test_claim_leases_due_pending_rows_oldest_due_first_up_to_the_limit(): void {
    $store = $this->store();
    $store->append(self::record('later', '2026-10-01 11:59:00'));
    $store->append(self::record('first', '2026-10-01 11:00:00'));
    $store->append(self::record('second', '2026-10-01 11:00:00'));
    $store->append(self::record('future', '2026-10-01 12:00:01'));

    self::assertSame(['first', 'second'], self::ids($store->claim(2, $this->clock->now(), 60)));
    self::assertSame(['later'], self::ids($store->claim(5, $this->clock->now(), 60)), 'leased rows are skipped');
    self::assertSame([], $store->claim(5, $this->clock->now(), 60));
    self::assertSame([], $store->claim(0, $this->clock->now(), 60));
  }

  public function test_claim_refuses_to_run_inside_an_open_transaction(): void {
    $store = $this->store();
    $this->db->begin();
    try {
      $this->expectException(NestedTransactionRejected::class);
      $store->claim(1, $this->clock->now(), 60);
    } finally {
      $this->db->rollBack();
    }
  }

  public function test_an_expired_lease_can_be_claimed_again(): void {
    $store = $this->store();
    $store->append(self::record('e1'));
    [$first] = $store->claim(1, $this->clock->now(), 60);

    self::assertSame([], $store->claim(1, $this->clock->now()->modify('+59 seconds'), 60));
    [$second] = $store->claim(1, $this->clock->now()->modify('+60 seconds'), 60);
    self::assertNotSame($first->claimToken, $second->claimToken);
  }

  public function test_claim_skips_rows_another_connection_has_locked(): void {
    $store = $this->store();
    $store->append(self::record('locked', '2026-10-01 11:00:00'));
    $store->append(self::record('free', '2026-10-01 11:30:00'));

    $other = $this->otherConnection();
    $other->begin();
    try {
      $other->fetchAll('SELECT id FROM tp_ddd_outbox WHERE event_id = ? FOR UPDATE', ['locked']);
      self::assertSame(['free'], self::ids($store->claim(5, $this->clock->now(), 60)), 'SKIP LOCKED, no wait');
    } finally {
      $other->rollBack();
    }
    self::assertSame(['locked'], self::ids($store->claim(5, $this->clock->now(), 60)));
  }

  public function test_two_workers_claiming_concurrently_never_share_a_row(): void {
    $store = $this->store();
    for ($i = 0; $i < 20; $i++) {
      $store->append(self::record("e$i", '2026-10-01 11:00:00'));
    }
    $workerB = $this->store($this->otherConnection());

    $seen = [];
    while (($batch = array_merge($store->claim(3, $this->clock->now(), 60), $workerB->claim(3, $this->clock->now(), 60))) !== []) {
      foreach ($batch as $c) {
        self::assertArrayNotHasKey($c->event_id, $seen);
        $seen[$c->event_id] = true;
      }
    }
    self::assertCount(20, $seen);
  }

  public function test_accept_is_fenced_by_the_claim_token(): void {
    $store = $this->store();
    $store->append(self::record('e1'));
    [$a] = $store->claim(1, $this->clock->now(), 60);

    // A's lease expires; B claims and accepts; A finishes late (relay.lease-fencing).
    $workerB = $this->store($this->otherConnection());
    [$b] = $workerB->claim(1, $this->clock->now()->modify('+61 seconds'), 60);
    self::assertTrue($workerB->accept($b, 'job:1'));

    self::assertFalse($store->accept($a, 'job:late'));
    self::assertFalse($store->retryLater($a, 'late', $this->clock->now()));
    self::assertFalse($store->deadLetter($a, 'late'));

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('accepted', $row['status']);
    self::assertSame('job:1', $row['transport_ref']);
    self::assertSame('2026-10-01 12:00:00.000000', $row['accepted_at']);
    self::assertNull($row['claim_token']);
    self::assertSame(0, $this->countRows('ddd_dlq'));
  }

  public function test_an_expired_lease_nobody_reclaimed_still_accepts(): void {
    $store = $this->store();
    $store->append(self::record('e1'));
    [$a] = $store->claim(1, $this->clock->now(), 1);
    $this->clock->advance('PT10M');

    self::assertTrue($store->accept($a, 'job:1'));
  }

  public function test_retry_later_counts_an_attempt_in_sql_and_gates_on_next_attempt_at(): void {
    $store = $this->store();
    $store->append(self::record('e1'));
    [$c] = $store->claim(1, $this->clock->now(), 60);

    self::assertTrue($store->retryLater($c, 'transport down', self::utc('2026-10-01 12:05:00')));

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame(1, (int) $row['attempts']);
    self::assertSame('transport down', $row['last_error']);
    self::assertSame('2026-10-01 12:00:00.000000', $row['due_at'], 'a retry never moves due_at (bug 3)');
    self::assertNull($row['claim_token']);

    self::assertSame([], $store->claim(1, self::utc('2026-10-01 12:04:59'), 60));
    [$again] = $store->claim(1, self::utc('2026-10-01 12:05:00'), 60);
    self::assertSame(1, $again->attempts);
  }

  public function test_dead_letter_moves_the_row_to_the_dlq_in_one_transaction(): void {
    $store = $this->store();
    $store->append(self::record('e1', extra: ['payload_signature' => ['k' => 1], 'is_unique' => true]));
    [$c] = $store->claim(1, $this->clock->now(), 60);
    $store->retryLater($c, 'first', $this->clock->now());
    [$c] = $store->claim(1, $this->clock->now(), 60);

    self::assertTrue($store->deadLetter($c, 'gave up'));

    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('dlq', $row['status'], 'the outbox row stays, so replay keeps event_id');
    self::assertSame(2, (int) $row['attempts']);
    $dlq = $this->row('ddd_dlq', 'event_id = ?', ['e1']);
    self::assertSame('gave up', $dlq['error']);
    self::assertSame(2, (int) $dlq['attempts']);
    self::assertSame($row['payload'], $dlq['payload']);
    self::assertSame('2026-10-01 12:00:00.000000', $dlq['dead_lettered_at']);
    self::assertSame([], $store->claim(1, $this->clock->now(), 60));
  }

  public function test_a_failed_dlq_insert_leaves_the_outbox_row_pending_and_leased(): void {
    $faulty = new FaultyConnection($this->db);
    $store = $this->store($faulty);
    $store->append(self::record('e1'));
    [$c] = $store->claim(1, $this->clock->now(), 60);
    $faulty->failStatement = '/INSERT INTO `tp_ddd_dlq`/';

    try {
      $store->deadLetter($c, 'gave up');
      self::fail('expected the injected failure');
    } catch (\RuntimeException) {
    }

    self::assertFalse($this->db->inTransaction());
    self::assertSame('pending', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
    self::assertSame(0, $this->countRows('ddd_dlq'));
    self::assertTrue($store->accept($c, 'job:1'), 'the lease is still ours');
  }

  public function test_outcomes_work_inside_an_ambient_transaction_shared_with_the_transport(): void {
    $store = $this->store();
    $store->append(self::record('e1'));
    $store->append(self::record('e2'));
    [$c1, $c2] = $store->claim(2, $this->clock->now(), 60);
    $boundary = new PdoTransactionBoundary($this->db);

    $boundary->run(function () use ($store, $c1, $c2) {
      self::assertTrue($store->accept($c1, 'job:1'));
      self::assertTrue($store->deadLetter($c2, 'rejected'));
    });

    self::assertSame('accepted', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
    self::assertSame('dlq', $this->row('ddd_outbox', 'event_id = ?', ['e2'])['status']);
    self::assertSame(1, $this->countRows('ddd_dlq'));
  }

  public function test_paused_types_are_not_claimed_with_the_pdo_pause_store(): void {
    $pauses = new PdoPauseStore($this->db, self::PREFIX, $this->clock);
    $this->pausedTypesAreNotClaimed($pauses);
  }

  public function test_paused_types_are_not_claimed_with_any_other_pause_store(): void {
    $this->pausedTypesAreNotClaimed(new InMemoryRelayPauseStore());
  }

  private function pausedTypesAreNotClaimed(IRelayPauseStore $pauses): void {
    $store = $this->store(pauses: $pauses);
    $store->append(self::record('p1', '2026-10-01 10:00:00', ['event_type' => 'acme_order_placed']));
    $store->append(self::record('p2', '2026-10-01 10:00:01', ['event_type' => 'acme_order.shipped']));
    $store->append(self::record('ok', '2026-10-01 11:00:00', ['event_type' => 'acme_user_joined']));
    $pauses->hold('deploy', 'acme_order*', null);

    self::assertSame(['ok'], self::ids($store->claim(1, $this->clock->now(), 60)), 'paused rows do not use up the limit');
    self::assertNull($this->row('ddd_outbox', 'event_id = ?', ['p1'])['claim_token'], 'paused rows are not leased');

    $pauses->release('deploy');
    self::assertSame(['p1', 'p2'], self::ids($store->claim(5, $this->clock->now(), 60)));
  }

  public function test_the_event_class_is_kept_when_the_writer_knows_it(): void {
    $store = $this->store();
    $store->appendFact(self::record('e1'), 'App\\OrderPlaced');
    $store->append(self::record('e2'));

    self::assertSame('App\\OrderPlaced', $store->eventClassOf('e1'));
    self::assertNull($store->eventClassOf('e2'));
    self::assertNull($store->eventClassOf('missing'));
  }

  public function test_the_store_exposes_its_connection_for_shared_connection_checks(): void {
    self::assertSame($this->db, $this->store()->connection());
    self::assertNotSame($this->db, $this->store(new PdoConnection(self::newPdo(static::emulatePrepares())))->connection());
  }
}
