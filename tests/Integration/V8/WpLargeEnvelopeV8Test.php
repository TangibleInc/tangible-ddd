<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Tests\Integration\V8\Fakes\V8BlobFact;
use TangibleDDD\WordPress\Adapter\ActionSchedulerTransport;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpDeliveryLedger;
use TangibleDDD\WordPress\Adapter\WpLargeEnvelope;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpRollbackDrain;

use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\register_delivery_hooks;

/**
 * Facts over Action Scheduler's 8000-byte args limit (D6, codec.large-payload
 * on wp): the transport schedules a by-reference envelope (the journey keys
 * plus a pointer to the outbox row), and the ledger gate of every
 * DDD-registered callback resolves it back to the full payload. Redelivery
 * args stay by reference.
 */
final class WpLargeEnvelopeV8Test extends V8TestCase {

  private FrozenClock $clock;

  private WpdbOutboxStore $store;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    WpLedgeredDelivery::resetForTests();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    $this->store = new WpdbOutboxStore(
      new OutboxRepository($this->config, new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox'))),
      $this->config,
      $this->clock,
    );
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::resetForTests();
    parent::tearDown();
  }

  /** Append, claim and submit a V8BlobFact; returns [event id, action id]. */
  private function relay(string $eventId, string $blob, ?\DateTimeImmutable $due = null): array {
    $fact = new V8BlobFact($blob);
    $due ??= $this->clock->now();
    $this->store->append(new OutboxRecord(
      $eventId, V8BlobFact::name(), V8BlobFact::integration_action(), '44444444-4444-4444-8444-444444444444', 1, null,
      $fact->integration_payload(), $due, false,
    ));
    [$claim] = $this->store->claim(1, $due, 60);
    $ref = (new ActionSchedulerTransport($this->config->as_group('outbox')))->submit(
      $claim,
      IntegrationEnvelope::wrap($claim->record->payload, $claim->record->correlation_id, 1, $claim->event_id),
      $claim->record->due_at,
    );
    self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $ref, 'Action Scheduler stored the action');
    self::assertTrue($this->store->accept($claim, (string) $ref));
    return [$eventId, (int) $ref];
  }

  public function test_a_small_envelope_keeps_the_0_6_action_shape(): void {
    [$id, $action] = $this->relay('b0000000-0000-4000-8000-000000000001', 'small');
    $args = \ActionScheduler::store()->fetch_action((string) $action)->get_args();
    self::assertSame('small', $args[0]['blob']);
    self::assertArrayNotHasKey(WpLargeEnvelope::MARKER, $args[0]);
  }

  public function test_an_envelope_over_the_args_limit_is_scheduled_by_reference_and_resolved_for_ddd_callbacks(): void {
    $blob = str_repeat('0123456789abcdef', 4096); // 64 KiB
    $received = [];
    integration_action(V8BlobFact::class, static function (array $payload) use (&$received): void {
      $received[] = $payload['blob'] ?? null;
    });

    [$id, $action] = $this->relay('b0000000-0000-4000-8000-000000000002', $blob);

    $args = \ActionScheduler::store()->fetch_action((string) $action)->get_args();
    self::assertArrayHasKey(WpLargeEnvelope::MARKER, $args[0]);
    self::assertArrayNotHasKey('blob', $args[0], 'the payload is not in the action args');
    self::assertSame($id, $args[0]['__event_id']);
    self::assertLessThanOrEqual(WpLargeEnvelope::ARGS_LIMIT, strlen((string) wp_json_encode($args)));

    \ActionScheduler::runner()->process_action($action, 'v8-test');

    self::assertSame([$blob], $received, 'the callback received the full payload');
    self::assertTrue((new WpDeliveryLedger($this->config->prefix()))->delivered(
      WpLedgeredDelivery::subscribers(V8BlobFact::integration_action())[0], $id,
    ));
  }

  public function test_a_failed_by_reference_delivery_is_redelivered_by_reference(): void {
    register_delivery_hooks($this->config);
    WpLedgeredDelivery::registerConsumer($this->config);
    $blob = str_repeat('x', 20000);
    $failures = 1;
    $received = [];
    integration_action(V8BlobFact::class, static function (array $payload) use (&$failures, &$received): void {
      if ($failures-- > 0) {
        throw new \RuntimeException('down once');
      }
      $received[] = strlen((string) ($payload['blob'] ?? ''));
    });

    [$id, $action] = $this->relay('b0000000-0000-4000-8000-000000000003', $blob);
    \ActionScheduler::runner()->process_action($action, 'v8-test');
    self::assertSame([], $received);

    $redeliveries = $this->pendingActions($this->config->hook('ddd_redeliver'));
    self::assertCount(1, $redeliveries, 'Action Scheduler stored the redelivery (its args are by reference)');
    self::assertArrayHasKey(WpLargeEnvelope::MARKER, $redeliveries[0]->args['payload']);
    \ActionScheduler::runner()->process_action($redeliveries[0]->id, 'v8-test');

    self::assertSame([20000], $received);
  }

  public function test_drain_before_rollback_runs_due_by_reference_facts_and_counts_future_ones(): void {
    HostDefaults::provide(IClock::class, $this->clock);
    $received = [];
    integration_action(V8BlobFact::class, static function (array $payload) use (&$received): void {
      $received[] = strlen((string) ($payload['blob'] ?? ''));
    });
    $this->relay('b0000000-0000-4000-8000-000000000005', str_repeat('d', 9000));
    [, $small] = $this->relay('b0000000-0000-4000-8000-000000000006', 'small');
    $this->relay('b0000000-0000-4000-8000-000000000007', str_repeat('f', 9000), $this->clock->now()->modify('+1 day'));

    $drain = new WpRollbackDrain($this->config);
    $report = $drain->run();

    self::assertSame([9000], $received, 'the due by-reference fact ran; a 0.6 winner could not resolve it');
    self::assertSame(1, $report['remaining'], 'the future by-reference fact is counted: the rollback waits for it');
    self::assertSame(\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler::store()->get_status((string) $small), 'a small fact keeps the 0.6 shape and is left to the winner');
  }

  public function test_a_reference_whose_outbox_row_is_gone_fails_the_subscriber_not_silently(): void {
    $received = [];
    integration_action(V8BlobFact::class, static function (array $payload) use (&$received): void {
      $received[] = $payload;
    });
    [$id, $action] = $this->relay('b0000000-0000-4000-8000-000000000004', str_repeat('y', 9000));
    $this->wpdb->query($this->wpdb->prepare("DELETE FROM `{$this->table('integration_outbox')}` WHERE event_id = %s", $id));

    \ActionScheduler::runner()->process_action($action, 'v8-test');

    self::assertSame([], $received, 'the callback never runs on a missing payload');
    $ledger = new WpDeliveryLedger($this->config->prefix());
    $subscriber = WpLedgeredDelivery::subscribers(V8BlobFact::integration_action())[0];
    self::assertSame(1, $ledger->attempts($subscriber, $id));
    self::assertStringContainsString('outbox row', (string) $ledger->lastError($subscriber, $id));
  }
}
