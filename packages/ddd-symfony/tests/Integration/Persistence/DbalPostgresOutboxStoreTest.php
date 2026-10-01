<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use Psr\Log\NullLogger;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\RecordingSignalDispatcher;
use TangibleDDD\Testing\InMemoryRelayPauseStore;

final class DbalPostgresOutboxStoreTest extends PostgresTestCase {

  private \DateTimeImmutable $t0;

  protected function setUp(): void {
    parent::setUp();
    $this->t0 = new \DateTimeImmutable('2026-10-01T12:00:00Z');
  }

  private function record(string $id, string $type = 'widget_registered', ?\DateTimeImmutable $due = null, bool $unique = false, array $payload = ['widget_id' => 'w1'], int $max = 5): OutboxRecord {
    return new OutboxRecord(
      event_id: $id,
      event_type: $type,
      integration_action: 'txp_integration_' . $type,
      correlation_id: 'corr-1',
      sequence: 2,
      command_id: 'cmd-1',
      payload: $payload,
      due_at: $due ?? $this->t0,
      is_unique: $unique,
      payload_signature: $unique ? $payload : null,
      max_attempts: $max,
    );
  }

  private function rowStatus(string $id): ?string {
    $s = $this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$id]);
    return $s === false ? null : (string) $s;
  }

  public function test_append_then_claim_round_trips_the_record(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $due = new \DateTimeImmutable('2026-10-01T13:00:00+01:00'); // == 12:00Z
    $store->appendFact($this->record('e1', due: $due, payload: ['widget_id' => 'w1', 'n' => 3, 'nested' => ['a' => null]]), 'App\\WidgetRegistered');

    $claims = $store->claim(10, $this->t0, 300);

    self::assertCount(1, $claims);
    $c = $claims[0];
    self::assertSame('e1', $c->event_id);
    self::assertSame(0, $c->attempts);
    self::assertNotSame('', $c->claimToken);
    self::assertEquals($this->t0->modify('+300 seconds'), $c->leaseUntil);
    self::assertSame('widget_registered', $c->record->event_type);
    self::assertSame('txp_integration_widget_registered', $c->record->integration_action);
    self::assertSame(['widget_id' => 'w1', 'n' => 3, 'nested' => ['a' => null]], $c->record->payload);
    self::assertSame('corr-1', $c->record->correlation_id);
    self::assertSame(2, $c->record->sequence);
    self::assertSame('cmd-1', $c->record->command_id);
    self::assertEquals($this->t0, $c->record->due_at);
    self::assertSame('UTC', $c->record->due_at->getTimezone()->getName());
    self::assertSame('App\\WidgetRegistered', $store->eventClassOf('e1'));
  }

  public function test_append_without_a_class_leaves_event_class_null(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('e1'));
    self::assertNull($store->eventClassOf('e1'));
  }

  public function test_a_duplicate_event_id_is_a_write_failure(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('e1'));

    $this->expectException(OutboxWriteFailed::class);
    $store->append($this->record('e1'));
  }

  public function test_append_joins_the_ambient_transaction_and_rolls_back_with_it(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $boundary = new DbalTransactionBoundary($this->db);

    try {
      $boundary->run(function () use ($store) {
        $store->append($this->record('e1'));
        throw new \RuntimeException('handler fails after the fact was staged');
      });
    } catch (\RuntimeException) {
    }

    self::assertNull($this->rowStatus('e1'));
  }

  public function test_claim_takes_only_due_rows_oldest_first_up_to_the_limit(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('late', due: $this->t0->modify('+1 hour')));
    $store->append($this->record('b', due: $this->t0->modify('-1 minute')));
    $store->append($this->record('a', due: $this->t0->modify('-2 minutes')));
    $store->append($this->record('c', due: $this->t0));

    $ids = array_map(fn ($c) => $c->event_id, $store->claim(2, $this->t0, 60));

    self::assertSame(['a', 'b'], $ids);
    self::assertSame(['c'], array_map(fn ($c) => $c->event_id, $store->claim(10, $this->t0, 60)), 'leased rows are not re-claimed');
  }

  public function test_claim_inside_an_open_transaction_is_rejected(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $this->db->beginTransaction();

    $this->expectException(NestedTransactionRejected::class);
    $store->claim(10, $this->t0, 60);
  }

  public function test_claim_skips_rows_another_session_has_locked(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('locked'));
    $store->append($this->record('free'));

    $other = $this->secondConnection();
    $other->beginTransaction();
    $other->fetchAllAssociative("SELECT id FROM ddd_outbox WHERE event_id = 'locked' FOR UPDATE");

    $ids = array_map(fn ($c) => $c->event_id, $store->claim(10, $this->t0, 60));
    $other->rollBack();

    self::assertSame(['free'], $ids);
  }

  public function test_lease_fencing_a_late_holder_affects_nothing(): void {
    $a = new DbalPostgresOutboxStore($this->db);
    $b = new DbalPostgresOutboxStore($this->secondConnection());
    $a->append($this->record('e1'));

    [$claimA] = $a->claim(1, $this->t0, 60);
    $afterExpiry = $this->t0->modify('+61 seconds');
    [$claimB] = $b->claim(1, $afterExpiry, 60);
    self::assertNotSame($claimA->claimToken, $claimB->claimToken);

    self::assertTrue($b->accept($claimB, 'msg-9'));
    self::assertFalse($a->accept($claimA, 'msg-1'));
    self::assertFalse($a->retryLater($claimA, 'late', $afterExpiry));
    self::assertFalse($a->deadLetter($claimA, 'late'));

    // B's re-claim of A's expired lease counted as one attempt.
    $row = $this->db->fetchAssociative('SELECT status, transport_ref, attempts, claim_token FROM ddd_outbox WHERE event_id = ?', ['e1']);
    self::assertSame(['status' => 'accepted', 'transport_ref' => 'msg-9', 'attempts' => 1, 'claim_token' => null], $row);
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'));
  }

  /**
   * A submission that kills the process (fatal, OOM, SIGKILL) never writes an
   * outcome; its lease just expires. Each such re-claim counts as an attempt,
   * so the row cannot be re-claimed forever.
   */
  public function test_reclaiming_an_expired_lease_counts_an_attempt(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('e1'));
    [$first] = $store->claim(1, $this->t0, 60);
    self::assertSame(0, $first->attempts);

    [$second] = $store->claim(1, $this->t0->modify('+61 seconds'), 60);

    self::assertSame(1, $second->attempts);
    self::assertStringContainsString('lease expired', (string) $this->db->fetchOne("SELECT last_error FROM ddd_outbox WHERE event_id = 'e1'"));
    // an unleased row (first claim, or after retryLater) is not counted
    self::assertTrue($store->retryLater($second, 'broker down', $this->t0->modify('+61 seconds')));
    [$third] = $store->claim(1, $this->t0->modify('+62 seconds'), 60);
    self::assertSame(2, $third->attempts);
  }

  public function test_a_row_whose_lease_expires_max_attempts_times_is_dead_lettered_at_claim(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->appendFact($this->record('crashy'), 'App\\WidgetRegistered');
    $store->append($this->record('fine', due: $this->t0->modify('+1 second')));

    $t = $this->t0;
    for ($i = 0; $i < 5; $i++) { // max_attempts = 5: claims 2..5 count attempts 1..4
      $ids = array_map(static fn ($c) => $c->event_id, $store->claim(1, $t, 60));
      self::assertSame(['crashy'], $ids, "claim #$i");
      $t = $t->modify('+61 seconds');
    }

    // the 6th claim would be attempt 5 = max_attempts: dead-lettered, not handed out
    $ids = array_map(static fn ($c) => $c->event_id, $store->claim(2, $t, 60));

    self::assertSame(['fine'], $ids, 'the crashing row is no longer handed out');
    self::assertSame('dlq', $this->rowStatus('crashy'));
    $dlq = $this->db->fetchAssociative('SELECT error, attempts, event_class FROM ddd_dlq WHERE event_id = ?', ['crashy']);
    self::assertStringContainsString('lease expired', $dlq['error']);
    self::assertSame(5, $dlq['attempts']);
    self::assertSame('App\\WidgetRegistered', $dlq['event_class']);

    $taken = $store->takeDeadLetteredAtClaim();
    self::assertCount(1, $taken, 'the claim-time dead letter is visible to the relay');
    self::assertSame('crashy', $taken[0][0]->event_id);
    self::assertSame(5, $taken[0][0]->attempts);
    self::assertStringContainsString('lease expired', $taken[0][1]);
    self::assertSame([], $store->takeDeadLetteredAtClaim(), 'taken once');
  }

  public function test_the_relay_reports_and_signals_a_claim_time_dead_letter(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('crashy', max: 2));
    $store->claim(1, $this->t0, 60);
    $store->claim(1, $this->t0->modify('+61 seconds'), 60); // attempt 1 counted
    $signals = new RecordingSignalDispatcher();
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $signals);
    try {
      $relay = new Relay($store, new InMemoryTransport(), new DbalTransactionBoundary($this->db),
        new FrozenClock($this->t0->modify('+122 seconds')), new OutboxConfig(), new NullLogger());

      $report = $relay->runOnce(10);

      self::assertSame([], $report->claimed, 'not handed out');
      self::assertSame(['crashy'], $report->deadLettered);
      self::assertCount(1, $signals->emitted);
      self::assertInstanceOf(OutboxDeadLettered::class, $signals->emitted[0]['event']);
    } finally {
      HostDefaults::resetForTests();
    }
  }

  public function test_an_expired_lease_nobody_reclaimed_still_matches(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('e1'));
    [$claim] = $store->claim(1, $this->t0, 1);

    self::assertTrue($store->accept($claim, 'ref'));
  }

  public function test_retry_later_counts_the_attempt_and_gates_on_next_attempt_at(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('e1'));
    [$claim] = $store->claim(1, $this->t0, 60);

    self::assertTrue($store->retryLater($claim, 'broker down', $this->t0->modify('+60 seconds')));

    self::assertSame([], $store->claim(1, $this->t0->modify('+59 seconds'), 60));
    [$again] = $store->claim(1, $this->t0->modify('+60 seconds'), 60);
    self::assertSame(1, $again->attempts);
    self::assertSame('broker down', $this->db->fetchOne("SELECT last_error FROM ddd_outbox WHERE event_id = 'e1'"));
  }

  public function test_dead_letter_moves_the_row_and_keeps_the_outbox_row(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->appendFact($this->record('e1'), 'App\\WidgetRegistered');
    [$claim] = $store->claim(1, $this->t0, 60);

    self::assertTrue($store->deadLetter($claim, 'gave up'));

    self::assertSame('dlq', $this->rowStatus('e1'));
    $dlq = $this->db->fetchAssociative('SELECT event_id, error, attempts, event_class, payload FROM ddd_dlq');
    self::assertSame('e1', $dlq['event_id']);
    self::assertSame('gave up', $dlq['error']);
    self::assertSame(1, $dlq['attempts']);
    self::assertSame('App\\WidgetRegistered', $dlq['event_class']);
    self::assertSame(['widget_id' => 'w1'], json_decode($dlq['payload'], true));
    self::assertSame([], $store->claim(1, $this->t0->modify('+1 day'), 60));
  }

  public function test_is_unique_cancels_older_unleased_pending_duplicates_only(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $store->append($this->record('leased', unique: true, payload: ['k' => 1, 'j' => 2]));
    self::assertSame(['leased'], array_map(fn ($c) => $c->event_id, $store->claim(1, $this->t0, 60)));
    $store->append($this->record('old', unique: true, payload: ['k' => 1, 'j' => 2])); // leaves the leased row alone
    $store->append($this->record('other', unique: true, payload: ['k' => 2]));
    self::assertSame('pending', $this->rowStatus('old'));

    $store->append($this->record('new', unique: true, payload: ['j' => 2, 'k' => 1])); // same signature, other key order

    self::assertSame('cancelled', $this->rowStatus('old'));
    self::assertSame('pending', $this->rowStatus('leased'));
    self::assertSame('pending', $this->rowStatus('other'));
    self::assertSame('pending', $this->rowStatus('new'));
  }

  public function test_paused_rows_are_not_claimed_with_the_dbal_pause_store(): void {
    $pauses = new DbalRelayPauseStore($this->db);
    $store = new DbalPostgresOutboxStore($this->db, $pauses);
    $store->append($this->record('p', type: 'acme_order_placed'));
    $store->append($this->record('q', type: 'widget_registered'));
    $pauses->hold('ops', 'acme_order_*', null);

    self::assertSame(['q'], array_map(fn ($c) => $c->event_id, $store->claim(10, $this->t0, 60)));

    $pauses->release('ops');
    self::assertSame(['p'], array_map(fn ($c) => $c->event_id, $store->claim(10, $this->t0, 60)));
  }

  public function test_paused_rows_are_released_again_with_another_pause_store(): void {
    $pauses = new InMemoryRelayPauseStore();
    $store = new DbalPostgresOutboxStore($this->db, $pauses);
    $store->append($this->record('p', type: 'acme_order_placed'));
    $pauses->hold('ops', 'acme_order_*', null);

    self::assertSame([], $store->claim(10, $this->t0, 60));
    self::assertNull($this->db->fetchOne("SELECT claim_token FROM ddd_outbox WHERE event_id = 'p'"));

    $pauses->release('ops');
    self::assertCount(1, $store->claim(10, $this->t0, 60));
  }

  public function test_uses_the_table_prefix(): void {
    \TangibleDDD\Symfony\Persistence\PostgresSchema::apply($this->db, 'p_');
    try {
      $store = new DbalPostgresOutboxStore($this->db, tablePrefix: 'p_');
      $store->append($this->record('e1'));
      self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM p_ddd_outbox'));
      self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_outbox'));
    } finally {
      foreach (\TangibleDDD\Symfony\Persistence\PostgresSchema::tables() as $t) {
        $this->db->executeStatement('DROP TABLE IF EXISTS p_' . $t);
      }
    }
  }
}
