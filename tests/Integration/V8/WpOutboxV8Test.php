<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\WordPress\Adapter\ActionSchedulerTransport;
use TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpRelayPauseStore;

/**
 * The final wp outbox adapters on schema v8 (register 3.4, rulings on
 * statuses; WPC-1, WPC-3): fenced claim_token claims that also set
 * locked_until / locked_by, the host clock and the requested lease honoured,
 * `completed` written for `accepted`, pause rows beside the 0.6 option, and
 * the Action Scheduler ITransport.
 */
final class WpOutboxV8Test extends V8TestCase {

  private FrozenClock $clock;

  private WpRelayPauseStore $pauses;

  private WpdbOutboxStore $store;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    $this->pauses = new WpRelayPauseStore($this->config, $this->clock);
    $this->store = new WpdbOutboxStore($this->repository(), $this->config, $this->clock, $this->pauses);
  }

  private function repository(): OutboxRepository {
    return new OutboxRepository($this->config, new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox')));
  }

  private function record(string $id, ?\DateTimeImmutable $due = null, string $type = 'v8.fact', array $payload = ['n' => 1], bool $unique = false): OutboxRecord {
    return new OutboxRecord(
      $id, $type, $this->config->integration_action('v8_fact'), '22222222-2222-4222-8222-222222222222', 1, null,
      $payload, $due ?? $this->clock->now(), $unique, $unique ? $payload : null,
    );
  }

  /** @return array<string, mixed> */
  private function row(string $eventId): array {
    return $this->rows($this->wpdb->prepare("SELECT * FROM `{$this->table('integration_outbox')}` WHERE event_id = %s", $eventId))[0];
  }

  public function test_claim_sets_the_claim_token_and_the_0_6_lock_columns_for_the_requested_lease(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000001'));

    [$claim] = $this->store->claim(10, $this->clock->now(), 120);

    $row = $this->row($claim->event_id);
    self::assertNotSame('', $claim->token);
    self::assertSame($claim->token, $row['claim_token']);
    self::assertSame($this->clock->now()->modify('+120 seconds')->format('Y-m-d H:i:s'), $row['locked_until']);
    self::assertSame($claim->lease_until->format('Y-m-d H:i:s'), $row['locked_until']);
    self::assertNotEmpty($row['locked_by'], 'a 0.6 copy sees the lock owner');
    self::assertSame('pending', $row['status']);
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 120), 'a leased row is not claimed again');
    self::assertSame([], $this->repository()->fetch_pending(10, 'legacy-worker'), 'a 0.6 fetch skips the claimed row (locked_until)');
  }

  public function test_the_factory_serves_the_claim_token_store_only_to_a_migrated_consumer(): void {
    $repo = new \TangibleDDD\Infra\Persistence\OutboxRepository($this->config, new \TangibleDDD\Application\Outbox\OutboxConfig());
    self::assertInstanceOf(WpdbOutboxStore::class, \TangibleDDD\Runtime\HostDefaults::for(\TangibleDDD\Runtime\Outbox\IOutboxStore::class, $this->config, $repo));
    update_option($this->config->option('ddd_schema_version'), 7, false);
    self::assertNull(
      \TangibleDDD\Runtime\HostDefaults::for(\TangibleDDD\Runtime\Outbox\IOutboxStore::class, $this->config, $repo),
      'a v7 outbox has no claim_token column: the caller keeps the 0.6 repository path'
    );
  }

  public function test_claim_reads_the_given_now_not_the_wall_clock(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000002', $this->clock->now()->modify('+1 hour')));

    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));
    self::assertCount(1, $this->store->claim(10, $this->clock->now()->modify('+3601 seconds'), 60));
  }

  public function test_a_lost_lease_fences_accept_retry_and_dead_letter(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000003'));
    [$a] = $this->store->claim(1, $this->clock->now(), 60);
    [$b] = $this->store->claim(1, $this->clock->now()->modify('+61 seconds'), 60);

    self::assertNotSame($a->token, $b->token);
    self::assertFalse($this->store->accept($a, '17'));
    self::assertFalse($this->store->retry_later($a, 'late', $this->clock->now()));
    self::assertFalse($this->store->dead_letter($a, 'late'));
    self::assertSame('0', (string) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('integration_dlq')}`"));

    self::assertTrue($this->store->accept($b, '18'));
    $row = $this->row($b->event_id);
    self::assertSame(['completed', null, null, null], [$row['status'], $row['claim_token'], $row['locked_until'], $row['locked_by']], 'wp writes completed; accepted is the read alias');
    self::assertSame($this->clock->now()->format('Y-m-d H:i:s'), $row['processed_at']);
  }

  public function test_retry_later_counts_the_attempt_and_honours_next_at(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000004'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    $next = $this->clock->now()->modify('+600 seconds');

    self::assertTrue($this->store->retry_later($c, 'transport down', $next));

    $row = $this->row($c->event_id);
    self::assertSame(['pending', '1', 'transport down', $next->format('Y-m-d H:i:s'), null], [$row['status'], $row['attempts'], $row['last_error'], $row['next_attempt_at'], $row['claim_token']]);
    self::assertEquals([['attempt' => 1, 'error' => 'transport down', 'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')]], json_decode($row['error_history'], true));
    self::assertSame([], $this->store->claim(1, $next->modify('-1 second'), 60));
    [$again] = $this->store->claim(1, $next, 60);
    self::assertSame(1, $again->attempts);
  }

  public function test_dead_letter_counts_the_final_attempt_in_one_transaction(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000005'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);

    self::assertTrue($this->store->dead_letter($c, 'gave up'));

    $row = $this->row($c->event_id);
    self::assertSame(['dlq', '1', null], [$row['status'], $row['attempts'], $row['claim_token']]);
    $dlq = $this->rows("SELECT event_id, attempts, final_error, moved_at FROM `{$this->table('integration_dlq')}`");
    self::assertSame([['event_id' => $c->event_id, 'attempts' => '1', 'final_error' => 'gave up', 'moved_at' => $this->clock->now()->format('Y-m-d H:i:s')]], $dlq);
  }

  public function test_claim_refuses_to_run_inside_an_open_transaction(): void {
    $this->expectException(NestedTransactionRejected::class);
    (new WpdbTransactionBoundary(NestedPolicy::Reject))->run(fn () => $this->store->claim(1, $this->clock->now(), 60));
  }

  public function test_pause_rows_and_the_legacy_option_both_hold_the_relay(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000006', type: 'v8.order_paid'));
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000007', type: 'v8.other'));

    $this->pauses->hold('deploy', 'v8.order_*', $this->clock->now()->modify('+300 seconds'));
    $this->pauses->hold('ops', 'v8.other', null);
    self::assertTrue($this->pauses->is_paused('v8.order_paid', $this->clock->now()));
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));

    $this->pauses->release('ops');
    $claims = $this->store->claim(10, $this->clock->now(), 60);
    self::assertSame(['e0000000-0000-4000-8000-000000000007'], array_map(static fn (Claim $c) => $c->event_id, $claims));
    $this->store->accept($claims[0], '1');
    self::assertSame(['e0000000-0000-4000-8000-000000000006'], array_map(static fn (Claim $c) => $c->event_id, $this->store->claim(10, $this->clock->now()->modify('+301 seconds'), 60)), 'expiry honoured against the given now');
  }

  public function test_a_failed_claim_query_throws_instead_of_reporting_nothing_due(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000020'));
    $outbox = $this->table('integration_outbox');
    $this->wpdb->query("ALTER TABLE `$outbox` RENAME COLUMN next_attempt_at TO next_attempt_at_gone");
    $suppress = $this->wpdb->suppress_errors(true);
    try {
      $this->store->claim(10, $this->clock->now(), 60);
      self::fail('a failed claim SELECT must throw');
    } catch (\TangibleDDD\Runtime\Outbox\OutboxWriteFailed $e) {
      self::assertStringContainsString('Outbox claim failed', $e->getMessage());
    } finally {
      $this->wpdb->suppress_errors($suppress);
      $this->wpdb->query("ALTER TABLE `$outbox` RENAME COLUMN next_attempt_at_gone TO next_attempt_at");
    }
  }

  public function test_an_unreadable_pause_table_fails_closed(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000021'));
    $this->wpdb->query("DROP TABLE `{$this->table('ddd_relay_pauses')}`");
    $suppress = $this->wpdb->suppress_errors(true);
    try {
      self::assertTrue($this->pauses->is_paused('v8.fact', $this->clock->now()), 'a pause that cannot be read is assumed held');
      try {
        $this->store->claim(10, $this->clock->now(), 60);
        self::fail('the relay must not claim past an unreadable pause table');
      } catch (\RuntimeException $e) {
        self::assertStringContainsString('Relay pause read failed', $e->getMessage());
      }
    } finally {
      $this->wpdb->suppress_errors($suppress);
    }
    self::assertSame('pending', $this->row('e0000000-0000-4000-8000-000000000021')['status']);
    self::assertNull($this->row('e0000000-0000-4000-8000-000000000021')['claim_token']);
  }

  public function test_a_0_6_option_hold_is_read_until_it_is_drained(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000008'));
    $this->repository()->set_pause('legacy-holder', 'v8.fact');

    self::assertTrue($this->pauses->is_paused('v8.fact', $this->clock->now()));
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));

    $this->repository()->set_pause('legacy-holder', '*');
    self::assertTrue($this->pauses->is_paused('anything', $this->clock->now()));

    $this->repository()->clear_pause('legacy-holder');
    self::assertCount(1, $this->store->claim(10, $this->clock->now(), 60));
  }

  public function test_a_legacy_delayed_row_is_claimed_at_its_absolute_scheduled_at(): void {
    // A 0.6 row: delay_seconds beside the scheduled_at it already applied.
    SchemaV7::outbox($this->config, 'e0000000-0000-4000-8000-000000000009');
    $this->wpdb->update($this->table('integration_outbox'), ['delay_seconds' => 3600, 'scheduled_at' => $this->clock->now()->modify('-5 seconds')->format('Y-m-d H:i:s')], ['event_id' => 'e0000000-0000-4000-8000-000000000009']);

    [$c] = $this->store->claim(1, $this->clock->now(), 60);
    self::assertSame($this->clock->now()->modify('-5 seconds')->getTimestamp(), $c->record->due_at->getTimestamp(), 'never delayed again');
  }

  public function test_is_unique_cancels_only_unleased_pending_rows_with_the_same_signature(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000a', payload: ['k' => 1], unique: true));
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000b', payload: ['k' => 2], unique: true));
    [$leased] = $this->store->claim(1, $this->clock->now(), 60);   // ...0a, the oldest
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000c', payload: ['k' => 2], unique: true));
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000d', payload: ['k' => 1], unique: true));

    self::assertSame('e0000000-0000-4000-8000-00000000000a', $leased->event_id);
    self::assertSame([
      'e0000000-0000-4000-8000-00000000000a' => 'pending',
      'e0000000-0000-4000-8000-00000000000b' => 'cancelled',
      'e0000000-0000-4000-8000-00000000000c' => 'pending',
      'e0000000-0000-4000-8000-00000000000d' => 'pending',
    ], array_column($this->rows("SELECT event_id, status FROM `{$this->table('integration_outbox')}` ORDER BY id"), 'status', 'event_id'));
  }

  public function test_the_action_scheduler_transport_relays_on_the_0_6_action_shape(): void {
    $transport = new ActionSchedulerTransport($this->config->as_group('outbox'));
    self::assertTrue($transport->shares_connection($this->store));

    $due = $this->clock->now()->modify('-30 seconds');
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000e', $due));
    $relay = new OutboxProcessor(
      $this->config, null, new OutboxConfig(), null, null, null, $this->clock,
      $this->store, $transport, new WpdbTransactionBoundary(),
    );

    $result = $relay->process_batch();

    self::assertSame(1, $result->completed);
    $actions = $this->pendingActions($this->config->integration_action('v8_fact'));
    self::assertCount(1, $actions);
    self::assertSame($due->getTimestamp(), $actions[0]->due, 'the absolute due time is kept even when past (no relative delay)');
    $envelope = IntegrationEnvelope::unwrap($actions[0]->args[0]);
    self::assertSame('e0000000-0000-4000-8000-00000000000e', $envelope->event_id);
    self::assertSame(['n' => 1], $envelope->payload);
    $groups = $this->rows($this->wpdb->prepare(
      "SELECT g.slug FROM `{$this->wpdb->prefix}actionscheduler_actions` a JOIN `{$this->wpdb->prefix}actionscheduler_groups` g ON g.group_id = a.group_id WHERE a.action_id = %d",
      $actions[0]->id
    ));
    self::assertSame('ddd8it-outbox', $groups[0]['slug']);
    self::assertSame('completed', $this->row('e0000000-0000-4000-8000-00000000000e')['status']);
  }

  public function test_the_transport_returns_the_action_id_as_its_reference(): void {
    $transport = new ActionSchedulerTransport($this->config->as_group('outbox'));
    $this->store->append($this->record('e0000000-0000-4000-8000-00000000000f'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);

    $ref = $transport->submit($c, IntegrationEnvelope::wrap($c->record->payload, $c->record->correlation_id, 1, $c->event_id), $c->record->due_at);

    self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $ref);
    self::assertSame((int) $ref, $this->pendingActions($this->config->integration_action('v8_fact'))[0]->id);
  }

  public function test_administration_reads_the_host_clock_and_the_claim_lease(): void {
    $admin = new WpdbOutboxAdministration($this->config->prefix(), $this->clock);
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000010'));
    [$c] = $this->store->claim(1, $this->clock->now(), 60);

    try {
      $admin->retry($c->event_id, true);
      self::fail('a leased row is never retried');
    } catch (OutboxAdministrationRefused) {
    }

    $this->clock->advance('+61 seconds');
    $admin->retry($c->event_id);
    $row = $this->row($c->event_id);
    self::assertSame([null, null, $this->clock->now()->format('Y-m-d H:i:s')], [$row['claim_token'], $row['locked_until'], $row['next_attempt_at']]);

    [$c2] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->accept($c2, '1');
    self::assertSame(0, $admin->purge($this->clock->now()));
    $this->clock->advance('+1 day');
    self::assertSame(1, $admin->purge($this->clock->now()->modify('-1 hour')));
  }

  // ── CR-PDO-6 (wave3-notes ruling; CR-W4CE-9): expired-lease re-claims ─────

  public function test_the_store_reports_claim_time_dead_letters(): void {
    self::assertInstanceOf(IReportsClaimDeadLetters::class, $this->store);
    self::assertSame([], $this->store->take_claim_dead_letters());
  }

  public function test_a_re_claim_of_an_expired_lease_counts_one_attempt(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000020'));

    [$first] = $this->store->claim(1, $this->clock->now(), 30);
    self::assertSame(0, $first->attempts, 'a first claim counts nothing');

    $this->clock->advance('+31 seconds');
    [$second] = $this->store->claim(1, $this->clock->now(), 30);
    self::assertSame(1, $second->attempts, 'the expired lease counts as attempt 1');
    $row = $this->row($second->event_id);
    self::assertSame(['1', IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR, 'pending'], [$row['attempts'], $row['last_error'], $row['status']]);
    self::assertEquals(
      [['attempt' => 1, 'error' => IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR, 'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')]],
      json_decode((string) $row['error_history'], true),
      'the lost attempt is in the error history like any other',
    );
    self::assertSame([], $this->store->take_claim_dead_letters());
  }

  public function test_a_row_released_by_an_outcome_is_not_a_re_claim(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000021'));
    [$c] = $this->store->claim(1, $this->clock->now(), 30);
    self::assertTrue($this->store->retry_later($c, 'transport down', $this->clock->now()));

    $this->clock->advance('+31 seconds');
    [$again] = $this->store->claim(1, $this->clock->now(), 30);
    self::assertSame(1, $again->attempts, 'only the retryLater attempt counts');
    self::assertSame('transport down', $this->row($again->event_id)['last_error']);
  }

  public function test_an_operator_retry_clears_the_lease_so_the_next_claim_is_not_a_re_claim(): void {
    $admin = new WpdbOutboxAdministration($this->config->prefix(), $this->clock);
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000022'));
    $this->store->claim(1, $this->clock->now(), 30);

    $this->clock->advance('+31 seconds');
    $admin->retry('e0000000-0000-4000-8000-000000000022');
    [$c] = $this->store->claim(1, $this->clock->now(), 30);
    self::assertSame(0, $c->attempts);
  }

  public function test_the_re_claim_that_reaches_max_attempts_is_dead_lettered_at_claim(): void {
    $id = 'e0000000-0000-4000-8000-000000000023';
    $this->store->append(new OutboxRecord(
      $id, 'v8.fact', $this->config->integration_action('v8_fact'), '22222222-2222-4222-8222-222222222222', 1, null,
      ['n' => 1], $this->clock->now(), false, null, 3,
    ));
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000024'));

    $this->store->claim(1, $this->clock->now(), 30);                  // attempts 0
    foreach ([1, 2] as $n) {
      $this->clock->advance('+31 seconds');
      [$c] = $this->store->claim(1, $this->clock->now(), 30);
      self::assertSame([$id, $n], [$c->event_id, $c->attempts]);
    }

    $this->clock->advance('+31 seconds');
    $claims = $this->store->claim(1, $this->clock->now(), 30);
    self::assertSame([], $claims, 'not handed out; a claim-time dead letter is not replaced within the same limit');

    $taken = $this->store->take_claim_dead_letters();
    self::assertCount(1, $taken);
    [$claim, $error] = $taken[0];
    self::assertSame([$id, 3], [$claim->event_id, $claim->attempts]);
    self::assertStringContainsString(IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR, $error);
    self::assertSame([], $this->store->take_claim_dead_letters(), 'taken once');

    $row = $this->row($id);
    self::assertSame(['dlq', '3', null, null, null], [$row['status'], $row['attempts'], $row['claim_token'], $row['locked_until'], $row['locked_by']]);
    $dlq = $this->rows($this->wpdb->prepare("SELECT event_id, attempts, final_error FROM `{$this->table('integration_dlq')}` WHERE event_id = %s", $id));
    self::assertSame([['event_id' => $id, 'attempts' => '3', 'final_error' => $error]], $dlq);
    [$other] = $this->store->claim(10, $this->clock->now(), 30);
    self::assertSame('e0000000-0000-4000-8000-000000000024', $other->event_id, 'the next claim moves on');

    $letters = (new WpdbOutboxAdministration($this->config->prefix(), $this->clock))->dead_letters(10);
    self::assertSame([$id], array_map(static fn ($l) => $l->event_id, $letters));
    self::assertSame(3, $letters[0]->attempts);
  }

  public function test_the_core_relay_emits_the_claim_time_dead_letter(): void {
    $id = 'e0000000-0000-4000-8000-000000000025';
    $this->store->append(new OutboxRecord(
      $id, 'v8.fact', $this->config->integration_action('v8_fact'), '22222222-2222-4222-8222-222222222222', 1, null,
      ['n' => 1], $this->clock->now(), false, null, 2,
    ));
    $this->store->claim(1, $this->clock->now(), 30);
    $this->clock->advance('+31 seconds');
    $this->store->claim(1, $this->clock->now(), 30);
    $this->clock->advance('+31 seconds');

    $relay = new OutboxProcessor(
      $this->config, null, new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox')), null,
      null, null, $this->clock,
      $this->store, new ActionSchedulerTransport($this->config->as_group('outbox')), new WpdbTransactionBoundary(NestedPolicy::Reject),
    );
    $result = $relay->process_batch(10);

    self::assertSame([$id], $result->claim_dead_letters);
    self::assertSame([], $this->pendingActions($this->config->integration_action('v8_fact')), 'never transported');
  }
}
