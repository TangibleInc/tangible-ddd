<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ManualProcess;
use TangibleDDD\WordPress\Adapter\ActionSchedulerWakeupScheduler;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbWakeupScheduler;
use TangibleDDD\WordPress\Adapter\WpStrandedScan;

/**
 * Durable wakeup intents on wp (register 3.6, 5.3; ruling on rollback):
 * the `{prefix}_ddd_wakeups` row is the recovery ledger and the fencing
 * source; every intent is projected to Action Scheduler AT SCHEDULE TIME,
 * future-dated, on the legacy hook with the legacy associative args, so a
 * rolled-back 0.6 winner still fires it.
 */
final class WpWakeupsV8Test extends V8TestCase {

  private FrozenClock $clock;

  private WpdbWakeupScheduler $wakeups;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    $this->wakeups = new WpdbWakeupScheduler($this->config, $this->clock);
  }

  private function tx(callable $work): mixed {
    return (new WpdbTransactionBoundary())->run($work);
  }

  /** @return list<array<string, mixed>> */
  private function intents(): array {
    return $this->rows("SELECT idempotency_key, kind, process_id, step_index, status, attempts, as_action_id, UNIX_TIMESTAMP(due_at) AS due FROM `{$this->table('ddd_wakeups')}` ORDER BY id");
  }

  public function test_the_factory_serves_the_intent_table_only_to_a_migrated_consumer(): void {
    self::assertInstanceOf(WpdbWakeupScheduler::class, HostDefaults::for(IWakeupScheduler::class, $this->config));
    update_option($this->config->option('ddd_schema_version'), 7, false);
    self::assertInstanceOf(ActionSchedulerWakeupScheduler::class, HostDefaults::for(IWakeupScheduler::class, $this->config));
  }

  public function test_schedule_needs_the_process_store_transaction(): void {
    $this->expectException(WakeupOutsideTransaction::class);
    $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now()->modify('+1 hour')));
  }

  public function test_a_timeout_is_projected_future_dated_on_the_legacy_hook_with_associative_args(): void {
    $due = $this->clock->now()->modify('+26 hours');
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $due)));

    $actions = $this->pendingActions('ddd8it_await_timeout');
    self::assertCount(1, $actions);
    self::assertSame(['process_id' => 5, 'step_index' => 2], $actions[0]->args);
    self::assertSame($due->getTimestamp(), $actions[0]->due);
    self::assertSame([[
      'idempotency_key' => 'timeout:5:2', 'kind' => 'timeout', 'process_id' => '5', 'step_index' => '2',
      'status' => 'pending', 'attempts' => '0', 'as_action_id' => (string) $actions[0]->id, 'due' => (string) $due->getTimestamp(),
    ]], $this->intents());
  }

  public function test_a_due_continuation_is_enqueued_on_the_legacy_hook(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::continuation('ddd8it', 6, 1, $this->clock->now()->modify('-1 second'))));

    $actions = $this->pendingActions('ddd8it_process_continue');
    self::assertCount(1, $actions);
    self::assertSame(['process_id' => 6], $actions[0]->args);
  }

  public function test_a_duplicate_key_is_a_no_op_and_a_rolled_back_schedule_leaves_nothing(): void {
    $i = WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now()->modify('+1 hour'));
    $this->tx(fn () => $this->wakeups->schedule($i));
    $this->tx(fn () => $this->wakeups->schedule($i));
    self::assertCount(1, $this->intents());
    self::assertCount(1, $this->pendingActions('ddd8it_await_timeout'));

    try {
      $this->tx(function (): void {
        $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 9, 1, $this->clock->now()->modify('+1 hour')));
        throw new \RuntimeException('the state change failed');
      });
    } catch (\RuntimeException) {
    }
    self::assertCount(1, $this->intents(), 'the intent rolled back with the state change');
    self::assertCount(1, $this->pendingActions('ddd8it_await_timeout'), 'and so did its Action Scheduler projection (same connection)');
  }

  public function test_cancel_removes_the_row_state_and_the_projection(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now()->modify('+1 hour'))));
    $this->tx(fn () => $this->wakeups->cancel('timeout:5:2'));

    self::assertSame('cancelled', $this->intents()[0]['status']);
    self::assertSame([], $this->pendingActions('ddd8it_await_timeout'));
  }

  public function test_a_wake_marks_its_intent_firing_then_done_and_a_reschedule_while_firing_re_arms_it(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::continuation('ddd8it', 7, 3, $this->clock->now())));

    $this->wakeups->begin(WakeKind::Continue, 7, null);
    self::assertSame('firing', $this->intents()[0]['status']);
    $this->wakeups->finish(WakeKind::Continue, 7, null, null);
    self::assertSame('done', $this->intents()[0]['status']);

    // The wake of continue:7:3 reschedules the same key from inside itself.
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::continuation('ddd8it', 8, 1, $this->clock->now())));
    $this->wakeups->begin(WakeKind::Continue, 8, null);
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::continuation('ddd8it', 8, 1, $this->clock->now()->modify('+5 minutes'))));
    $this->wakeups->finish(WakeKind::Continue, 8, null, null);
    self::assertSame('pending', $this->intents()[1]['status'], 'not lost: re-armed while firing');
    self::assertSame((string) $this->clock->now()->modify('+5 minutes')->getTimestamp(), $this->intents()[1]['due']);
  }

  public function test_a_failed_wake_goes_back_to_pending_with_the_attempt_counted(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    $this->wakeups->begin(WakeKind::Timeout, 5, 2);
    $this->wakeups->finish(WakeKind::Timeout, 5, 2, 'Could not acquire lock');

    $row = $this->rows("SELECT status, attempts, last_error FROM `{$this->table('ddd_wakeups')}`")[0];
    self::assertSame(['pending', '1', 'Could not acquire lock'], array_values($row));
  }

  public function test_reproject_restores_a_missing_projection_of_a_due_intent_only(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now()->modify('-1 minute'))));
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 6, 1, $this->clock->now()->modify('+1 hour'))));
    self::assertSame(0, $this->wakeups->reproject($this->clock->now()), 'both projections still pending');

    as_unschedule_all_actions('ddd8it_await_timeout');
    self::assertSame(1, $this->wakeups->reproject($this->clock->now()), 'only the due intent is re-projected');
    $actions = $this->pendingActions('ddd8it_await_timeout');
    self::assertSame([['process_id' => 5, 'step_index' => 2]], array_column($actions, 'args'));
    self::assertSame((string) $actions[0]->id, $this->intents()[0]['as_action_id']);
  }

  public function test_claim_due_complete_and_retry_later_are_fenced(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));

    [$w] = $this->wakeups->claimDue($this->clock->now(), 10, 60);
    self::assertSame('timeout:5:2', $w->intent->idempotencyKey);
    self::assertSame(WakeKind::Timeout, $w->intent->kind);
    self::assertSame([], $this->wakeups->claimDue($this->clock->now(), 10, 60), 'leased');

    [$w2] = $this->wakeups->claimDue($this->clock->now()->modify('+61 seconds'), 10, 60);
    self::assertFalse($this->wakeups->complete($w), 'lost lease');
    self::assertTrue($this->wakeups->retryLater($w2, 'busy', $this->clock->now()->modify('+2 minutes')));
    self::assertSame([], $this->wakeups->claimDue($this->clock->now()->modify('+61 seconds'), 10, 60));
    [$w3] = $this->wakeups->claimDue($this->clock->now()->modify('+2 minutes'), 10, 60);
    self::assertSame(1, $w3->attempts);
    self::assertTrue($this->wakeups->complete($w3));
    self::assertSame('done', $this->intents()[0]['status']);
  }

  public function test_the_stranded_scan_mints_a_continuation_unless_a_0_6_action_is_queued(): void {
    $old = $this->clock->now()->modify('-20 minutes')->format('Y-m-d H:i:s');
    $bare = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 2, null);
    $queued = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 1, null);
    $running = SchemaV7::process($this->config, V8ManualProcess::class, 'running', 1, null);
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET updated_at = '$old'");
    as_enqueue_async_action('ddd8it_process_continue', ['process_id' => $queued], 'ddd8it-processes');

    $store = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $this->clock);
    $report = (new WpStrandedScan($this->config, $store, $this->wakeups, $this->clock))->run();

    self::assertSame([$bare], $report->minted);
    self::assertSame([$queued], $report->alreadyQueued);
    self::assertSame([$running], array_map(static fn ($s) => $s->processId, $report->running));
    self::assertSame(["continue:$bare:2"], array_column($this->intents(), 'idempotency_key'));
    self::assertEqualsCanonicalizing([['process_id' => $queued], ['process_id' => $bare]], array_column($this->pendingActions('ddd8it_process_continue'), 'args'), 'one action each, no duplicate for the 0.6-queued one');

    $again = (new WpStrandedScan($this->config, $store, $this->wakeups, $this->clock))->run();
    self::assertSame([], $again->minted, 'the minted intent is live now');
  }
}
