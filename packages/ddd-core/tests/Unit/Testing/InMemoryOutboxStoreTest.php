<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryRelayPauseStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

final class InMemoryOutboxStoreTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryRelayPauseStore $pauses;
  private InMemoryTransactionBoundary $tx;
  private InMemoryOutboxStore $store;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->pauses = new InMemoryRelayPauseStore();
    $this->tx = new InMemoryTransactionBoundary();
    $this->store = new InMemoryOutboxStore($this->clock, $this->pauses, $this->tx);
    $this->tx->enlist($this->store);
  }

  private function record(string $event_id, string $type = 'acme_order_placed', ?\DateTimeImmutable $due = null, bool $unique = false, ?array $signature = null): OutboxRecord {
    return new OutboxRecord(
      event_id: $event_id,
      event_type: $type,
      integration_action: 'acme_integration_' . $type,
      correlation_id: 'corr-1',
      sequence: 1,
      command_id: 'cmd-1',
      payload: ['order_id' => 7],
      due_at: $due ?? $this->clock->now(),
      is_unique: $unique,
      payload_signature: $signature,
      max_attempts: 5,
    );
  }

  public function test_implements_both_outbox_ports(): void {
    self::assertInstanceOf(IOutboxStore::class, $this->store);
    self::assertInstanceOf(IOutboxAdministration::class, $this->store);
  }

  public function test_record_normalises_due_at_to_utc(): void {
    $r = $this->record('e1', due: new \DateTimeImmutable('2026-10-01 14:00:00', new \DateTimeZone('Europe/Berlin')));
    self::assertSame('2026-10-01T12:00:00+00:00', $r->due_at->format(DATE_ATOM));
  }

  public function test_append_then_claim_returns_a_leased_claim(): void {
    $this->store->append($this->record('e1'));

    $claims = $this->store->claim(10, $this->clock->now(), 60);

    self::assertCount(1, $claims);
    self::assertSame('e1', $claims[0]->event_id);
    self::assertSame(0, $claims[0]->attempts);
    self::assertSame('2026-10-01T12:01:00+00:00', $claims[0]->lease_until->format(DATE_ATOM));
    self::assertNotSame('', $claims[0]->token);
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60), 'a leased row is not claimable again');
  }

  public function test_duplicate_event_id_append_throws(): void {
    $this->store->append($this->record('e1'));
    $this->expectException(OutboxWriteFailed::class);
    $this->store->append($this->record('e1'));
  }

  public function test_append_rolls_back_with_the_ambient_transaction(): void {
    try {
      $this->tx->run(function () {
        $this->store->append($this->record('e1'));
        throw new \RuntimeException('handler failed');
      });
    } catch (\RuntimeException) {
    }
    self::assertSame(0, $this->store->stats()['pending']);
  }

  public function test_claim_inside_an_open_transaction_is_rejected(): void {
    $this->expectException(NestedTransactionRejected::class);
    $this->tx->run(fn () => $this->store->claim(10, $this->clock->now(), 60));
  }

  public function test_a_delayed_row_is_due_once_at_its_absolute_time(): void {
    $this->store->append($this->record('e1', due: $this->clock->now()->modify('+300 seconds')));

    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));
    $this->clock->advance('PT300S');
    self::assertCount(1, $this->store->claim(10, $this->clock->now(), 60));
  }

  public function test_claims_come_in_due_order_and_respect_the_limit(): void {
    $this->store->append($this->record('late', due: $this->clock->now()->modify('-1 second')));
    $this->store->append($this->record('early', due: $this->clock->now()->modify('-10 seconds')));
    $this->store->append($this->record('mid', due: $this->clock->now()->modify('-5 seconds')));

    $claims = $this->store->claim(2, $this->clock->now(), 60);
    self::assertSame(['early', 'mid'], array_map(static fn ($c) => $c->event_id, $claims));
  }

  public function test_lease_fencing_a_late_holder_affects_nothing(): void {
    $this->store->append($this->record('e1'));
    [$a] = $this->store->claim(1, $this->clock->now(), 60);

    $this->clock->advance('PT61S'); // A's lease expires
    [$b] = $this->store->claim(1, $this->clock->now(), 60);
    self::assertSame('e1', $b->event_id);

    self::assertTrue($this->store->accept($b, 'as-99'));
    self::assertFalse($this->store->accept($a, 'as-1'), 'A finishes late: 0 rows');
    self::assertFalse($this->store->retry_later($a, 'late', $this->clock->now()));
    self::assertFalse($this->store->dead_letter($a, 'late'));
    self::assertSame(1, $this->store->stats()['accepted']);
    self::assertSame(0, $this->store->stats()['dead_letters']);
  }

  public function test_an_expired_but_unclaimed_lease_still_accepts_like_the_sql_fence(): void {
    $this->store->append($this->record('e1'));
    [$a] = $this->store->claim(1, $this->clock->now(), 60);
    $this->clock->advance('PT120S');

    self::assertTrue($this->store->accept($a, 'as-1'));
  }

  public function test_retry_later_counts_the_attempt_and_gates_on_next_attempt(): void {
    $this->store->append($this->record('e1'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);

    self::assertTrue($this->store->retry_later($c, 'transport down', $this->clock->now()->modify('+60 seconds')));
    self::assertSame([], $this->store->claim(1, $this->clock->now(), 60));

    $this->clock->advance('PT60S');
    [$again] = $this->store->claim(1, $this->clock->now(), 60);
    self::assertSame(1, $again->attempts);
  }

  public function test_dead_letter_moves_the_row_and_keeps_the_original_row_as_dlq(): void {
    $this->store->append($this->record('e1'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);

    self::assertTrue($this->store->dead_letter($c, 'poison'));

    $letters = $this->store->dead_letters(10);
    self::assertCount(1, $letters);
    self::assertSame('e1', $letters[0]->event_id);
    self::assertSame('poison', $letters[0]->error);
    self::assertSame(1, $this->store->stats()['dlq']);
    self::assertSame('dlq', $this->store->status_of('e1'));
  }

  public function test_paused_event_types_are_not_claimed_until_released(): void {
    $this->store->append($this->record('e1', 'acme_order_placed'));
    $this->store->append($this->record('e2', 'acme_user_joined'));
    $this->pauses->hold('ops', 'acme_order_*', null);

    $claims = $this->store->claim(10, $this->clock->now(), 60);
    self::assertSame(['e2'], array_map(static fn ($c) => $c->event_id, $claims));

    $this->pauses->release('ops');
    self::assertSame(['e1'], array_map(static fn ($c) => $c->event_id, $this->store->claim(10, $this->clock->now(), 60)));
  }

  public function test_is_unique_append_cancels_older_unleased_pending_duplicates_only(): void {
    $this->store->append($this->record('old-leased', unique: true, signature: ['user' => 1]));
    $this->store->claim(1, $this->clock->now(), 60); // leases old-leased
    $this->store->append($this->record('old-pending', unique: true, signature: ['user' => 1]));
    $this->store->append($this->record('other-user', unique: true, signature: ['user' => 2]));

    $this->store->append($this->record('new', unique: true, signature: ['user' => 1]));

    self::assertSame('cancelled', $this->store->status_of('old-pending'));
    self::assertSame('pending', $this->store->status_of('old-leased'));
    self::assertSame('pending', $this->store->status_of('other-user'));
    self::assertSame('pending', $this->store->status_of('new'));
  }

  public function test_replay_keeps_the_event_id_and_deletes_the_dlq_row(): void {
    $this->store->append($this->record('e1'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($c, 'poison');
    $dlq_id = $this->store->dead_letters(1)[0]->dlq_id;

    $this->store->replay($dlq_id);

    self::assertSame([], $this->store->dead_letters(10));
    self::assertSame('pending', $this->store->status_of('e1'));
    [$again] = $this->store->claim(1, $this->clock->now(), 60);
    self::assertSame('e1', $again->event_id);
    self::assertSame(0, $again->attempts);
  }

  public function test_replay_reinserts_a_row_that_was_purged_with_the_original_event_id(): void {
    $this->store->append($this->record('e1'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($c, 'poison');
    $this->store->forget('e1');

    $this->store->replay($this->store->dead_letters(1)[0]->dlq_id);

    self::assertSame('pending', $this->store->status_of('e1'));
  }

  public function test_replay_and_discard_of_an_unknown_dead_letter_throw(): void {
    try {
      $this->store->replay(404);
      self::fail('expected OutboxRowNotFound');
    } catch (OutboxRowNotFound) {
    }
    $this->expectException(OutboxRowNotFound::class);
    $this->store->discard(404);
  }

  public function test_discard_deletes_only_the_dlq_row(): void {
    $this->store->append($this->record('e1'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($c, 'poison');

    $this->store->discard($this->store->dead_letters(1)[0]->dlq_id);

    self::assertSame([], $this->store->dead_letters(10));
    self::assertSame('dlq', $this->store->status_of('e1'));
  }

  public function test_retry_resets_a_dlq_row_and_refuses_accepted_rows_unless_forced(): void {
    $this->store->append($this->record('dead'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($c, 'poison');
    $this->store->retry('dead');
    self::assertSame('pending', $this->store->status_of('dead'));

    [$c2] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c2, 'ref');
    try {
      $this->store->retry('dead');
      self::fail('expected a refusal for an accepted row');
    } catch (OutboxAdministrationRefused) {
    }
    $this->store->retry('dead', force: true);
    self::assertSame('pending', $this->store->status_of('dead'));
  }

  public function test_sfc5_retry_of_a_dead_lettered_row_removes_its_dlq_entry(): void {
    $this->store->append($this->record('dead'));
    $this->store->append($this->record('other'));
    foreach ($this->store->claim(2, $this->clock->now(), 60) as $c) {
      $this->store->dead_letter($c, 'poison');
    }
    self::assertSame(2, $this->store->stats()['dead_letters']);

    $this->store->retry('dead');

    self::assertSame(1, $this->store->stats()['dead_letters'], 'the retried row left the DLQ');
    self::assertSame(['other'], array_map(static fn ($d) => $d->event_id, $this->store->dead_letters(10)));
  }

  public function test_retry_always_refuses_a_leased_row_even_forced(): void {
    $this->store->append($this->record('e1'));
    $this->store->claim(1, $this->clock->now(), 60);

    $this->expectException(OutboxAdministrationRefused::class);
    $this->store->retry('e1', force: true);
  }

  public function test_retry_of_an_unknown_row_throws(): void {
    $this->expectException(OutboxRowNotFound::class);
    $this->store->retry('nope');
  }

  public function test_dead_letters_page_with_an_after_cursor(): void {
    foreach (['a', 'b', 'c'] as $id) {
      $this->store->append($this->record($id));
    }
    foreach ($this->store->claim(3, $this->clock->now(), 60) as $c) {
      $this->store->dead_letter($c, 'x');
    }

    $first = $this->store->dead_letters(2);
    self::assertSame(['a', 'b'], array_map(static fn ($d) => $d->event_id, $first));
    $rest = $this->store->dead_letters(2, (string) $first[1]->dlq_id);
    self::assertSame(['c'], array_map(static fn ($d) => $d->event_id, $rest));
  }

  public function test_purge_deletes_only_accepted_rows_older_than_the_cutoff(): void {
    $this->store->append($this->record('old'));
    $this->store->append($this->record('pending'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c, 'ref');
    $this->clock->advance('P8D');

    self::assertSame(1, $this->store->purge($this->clock->now()->modify('-7 days')));
    self::assertNull($this->store->status_of('old'));
    self::assertSame('pending', $this->store->status_of('pending'));
  }

  public function test_stats_count_every_status(): void {
    $this->store->append($this->record('e1'));
    self::assertSame(
      ['pending' => 1, 'accepted' => 0, 'dlq' => 0, 'cancelled' => 0, 'dead_letters' => 0],
      $this->store->stats()
    );
  }
}
