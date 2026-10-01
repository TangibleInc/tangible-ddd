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
    self::assertNotSame('', $claim->claimToken);
    self::assertSame($claim->claimToken, $row['claim_token']);
    self::assertSame($this->clock->now()->modify('+120 seconds')->format('Y-m-d H:i:s'), $row['locked_until']);
    self::assertSame($claim->leaseUntil->format('Y-m-d H:i:s'), $row['locked_until']);
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

    self::assertNotSame($a->claimToken, $b->claimToken);
    self::assertFalse($this->store->accept($a, '17'));
    self::assertFalse($this->store->retryLater($a, 'late', $this->clock->now()));
    self::assertFalse($this->store->deadLetter($a, 'late'));
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

    self::assertTrue($this->store->retryLater($c, 'transport down', $next));

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

    self::assertTrue($this->store->deadLetter($c, 'gave up'));

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
    self::assertTrue($this->pauses->isPaused('v8.order_paid', $this->clock->now()));
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));

    $this->pauses->release('ops');
    $claims = $this->store->claim(10, $this->clock->now(), 60);
    self::assertSame(['e0000000-0000-4000-8000-000000000007'], array_map(static fn (Claim $c) => $c->event_id, $claims));
    $this->store->accept($claims[0], '1');
    self::assertSame(['e0000000-0000-4000-8000-000000000006'], array_map(static fn (Claim $c) => $c->event_id, $this->store->claim(10, $this->clock->now()->modify('+301 seconds'), 60)), 'expiry honoured against the given now');
  }

  public function test_a_0_6_option_hold_is_read_until_it_is_drained(): void {
    $this->store->append($this->record('e0000000-0000-4000-8000-000000000008'));
    $this->repository()->set_pause('legacy-holder', 'v8.fact');

    self::assertTrue($this->pauses->isPaused('v8.fact', $this->clock->now()));
    self::assertSame([], $this->store->claim(10, $this->clock->now(), 60));

    $this->repository()->set_pause('legacy-holder', '*');
    self::assertTrue($this->pauses->isPaused('anything', $this->clock->now()));

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
    self::assertTrue($transport->sharesConnectionWith($this->store));

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
}
