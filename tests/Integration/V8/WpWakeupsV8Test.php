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

  public function test_the_pre_rollback_drain_re_projects_a_failed_wake_still_in_its_backoff(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now()->modify('-1 second'))));
    // The wake ran and failed (lock contention): the intent is pending again
    // with a backoff, and its Action Scheduler action is gone.
    $this->wpdb->query("UPDATE `{$this->table('ddd_wakeups')}` SET attempts = 1, last_error = 'lock', due_at = '" . gmdate('Y-m-d H:i:s', time() + 120) . "'");
    as_unschedule_all_actions('ddd8it_await_timeout');
    self::assertSame(0, $this->wakeups->reproject($this->clock->now()), 'the tick waits for the backoff');
    self::assertSame(1, $this->wakeups->unprojected());

    $result = (new \TangibleDDD\WordPress\Adapter\WpRollbackDrain($this->config))->run();

    self::assertSame(0, $result['remaining']);
    $actions = $this->pendingActions('ddd8it_await_timeout');
    self::assertCount(1, $actions, 'on its legacy hook, which a 0.6 winner fires');
    self::assertSame(['process_id' => 5, 'step_index' => 2], $actions[0]->args);
    self::assertEqualsWithDelta(time() + 120, $actions[0]->due, 5, 'future-dated to its backoff');
    self::assertSame(0, $this->wakeups->unprojected());
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

  public function test_a_wake_that_always_throws_stops_after_10_attempts_spaced_by_the_backoff(): void {
    // Register 5.1, wake layer: 10 attempts, backoff 2 s × 2^n capped at
    // 300 s, exhaustion to a stranded process (layer wakeup).
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    $start = $this->clock->now()->getTimestamp();
    $gaps = [];

    for ($n = 0; $n < WpdbWakeupScheduler::WAKE_BUDGET; $n++) {
      if ($n > 0) {
        // Action Scheduler marked the last action failed: it is gone.
        as_unschedule_all_actions('ddd8it_await_timeout');
        $due = (int) $this->intents()[0]['due'];
        $this->clock->set(new \DateTimeImmutable('@' . ($due - 1)));
        self::assertSame(0, $this->wakeups->reproject($this->clock->now()), "retry $n is not due before its backoff");
        $this->clock->set(new \DateTimeImmutable('@' . $due));
        self::assertSame(1, $this->wakeups->reproject($this->clock->now()), "retry $n is re-projected once due");
      }
      $failedAt = $this->clock->now()->getTimestamp();
      $this->wakeups->begin(WakeKind::Timeout, 5, 2);
      $this->wakeups->finish(WakeKind::Timeout, 5, 2, 'Process #5 was quarantined');
      $row = $this->intents()[0];
      self::assertSame((string) ($n + 1), $row['attempts']);
      if ($n + 1 < WpdbWakeupScheduler::WAKE_BUDGET) {
        self::assertSame('pending', $row['status']);
        $gaps[] = (int) $row['due'] - $failedAt;
      }
    }

    self::assertSame([2, 4, 8, 16, 32, 64, 128, 256, 300], $gaps, 'backoff 2 s × 2^n capped at 300 s');
    self::assertSame('exhausted', $this->intents()[0]['status']);
    self::assertSame('10', $this->intents()[0]['attempts']);
    as_unschedule_all_actions('ddd8it_await_timeout');
    $this->clock->set(new \DateTimeImmutable('@' . ($start + 86400)));
    self::assertSame(0, $this->wakeups->reproject($this->clock->now()), 'an exhausted intent is never re-projected');
    self::assertFalse($this->wakeups->has_live_intent(5));

    $ops = (new \TangibleDDD\WordPress\Adapter\WpOperatorView($this->config, $this->clock))->list('wakeup');
    self::assertSame([['timeout:5:2', 10, 10, 'Process #5 was quarantined', ['rearm']]], array_map(
      static fn (array $r) => [$r['key'], $r['attempts'], $r['budget'], $r['last_error'], $r['repair_actions']],
      $ops
    ));
  }

  public function test_rescheduling_a_failed_key_keeps_its_attempts_and_a_successful_wake_clears_them(): void {
    $i = WakeupIntent::continuation('ddd8it', 8, 1, $this->clock->now());
    $this->tx(fn () => $this->wakeups->schedule($i));
    $this->wakeups->begin(WakeKind::Continue, 8, null);
    $this->wakeups->finish(WakeKind::Continue, 8, null, 'boom');

    // The failed wake rescheduled its own key from inside itself, then threw.
    $this->wakeups->begin(WakeKind::Continue, 8, null);
    $this->tx(fn () => $this->wakeups->schedule($i));
    $this->wakeups->finish(WakeKind::Continue, 8, null, 'boom again');
    self::assertSame(['pending', '1'], [$this->intents()[0]['status'], $this->intents()[0]['attempts']], 're-arming does not reset the budget');

    $this->wakeups->begin(WakeKind::Continue, 8, null);
    $this->tx(fn () => $this->wakeups->schedule($i));
    $this->wakeups->finish(WakeKind::Continue, 8, null, null);
    self::assertSame(['pending', '0'], [$this->intents()[0]['status'], $this->intents()[0]['attempts']], 'a wake that returned clears the count of the key it re-armed');
  }

  public function test_an_exhausted_key_is_not_reset_by_a_reschedule_and_rearm_restores_it(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    $this->wpdb->query("UPDATE `{$this->table('ddd_wakeups')}` SET status = 'exhausted', attempts = 10, last_error = 'lock'");
    as_unschedule_all_actions('ddd8it_await_timeout');

    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    self::assertSame(['exhausted', '10'], [$this->intents()[0]['status'], $this->intents()[0]['attempts']], 'only an operator re-arms an exhausted wake');
    self::assertSame([], $this->pendingActions('ddd8it_await_timeout'));

    self::assertTrue($this->wakeups->rearm('timeout:5:2'));
    self::assertSame(['pending', '0'], [$this->intents()[0]['status'], $this->intents()[0]['attempts']]);
    self::assertCount(1, $this->pendingActions('ddd8it_await_timeout'));
    self::assertFalse($this->wakeups->rearm('timeout:5:2'), 'only an exhausted key is re-armed');
  }

  public function test_a_wake_of_a_quarantined_process_closes_its_intent(): void {
    HostDefaults::provide(\TangibleDDD\Runtime\IClock::class, $this->clock);
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    // decode.unknown-class (wave 4): the worker continues, so the wake does
    // not fail its Action Scheduler action; the quarantine is logged and the
    // row (status failed, quarantine_reason) is what the operator sees.
    \TangibleDDD\WordPress\Adapter\WpWakeBracket::run($this->config, WakeKind::Timeout, 5, 2, static function (): void {
      throw new \TangibleDDD\Runtime\Process\QuarantinedProcess('Process #5 was quarantined: gone');
    });
    $row = $this->rows("SELECT status, attempts, last_error FROM `{$this->table('ddd_wakeups')}`")[0];
    self::assertSame(['cancelled', '1', 'Process #5 was quarantined: gone'], array_values($row), 'a quarantined process is never woken again');
  }

  public function test_the_stranded_scan_does_not_re_mint_an_exhausted_continuation(): void {
    $old = $this->clock->now()->modify('-20 minutes')->format('Y-m-d H:i:s');
    $pid = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 2, null);
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET updated_at = '$old'");
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::continuation('ddd8it', $pid, 2, $this->clock->now())));
    $this->wpdb->query("UPDATE `{$this->table('ddd_wakeups')}` SET status = 'exhausted', attempts = 10");
    as_unschedule_all_actions('ddd8it_process_continue');

    $store = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $this->clock);
    $report = (new WpStrandedScan($this->config, $store, $this->wakeups, $this->clock))->run();

    self::assertSame([], $report->minted);
    self::assertSame([$pid], $report->exhausted);
    self::assertSame([], $this->pendingActions('ddd8it_process_continue'));
  }

  public function test_a_failed_storage_write_throws(): void {
    $this->tx(fn () => $this->wakeups->schedule(WakeupIntent::timeout('ddd8it', 5, 2, $this->clock->now())));
    $this->wpdb->query("DROP TABLE `{$this->table('ddd_wakeups')}`");
    $suppress = $this->wpdb->suppress_errors(true);
    try {
      foreach ([
        'begin' => fn () => $this->wakeups->begin(WakeKind::Timeout, 5, 2),
        'finish' => fn () => $this->wakeups->finish(WakeKind::Timeout, 5, 2, 'x'),
        'finish_key' => fn () => $this->wakeups->finish_key('timeout:5:2', null),
        'claim_due' => fn () => $this->wakeups->claim_due($this->clock->now(), 10, 60),
        'reproject' => fn () => $this->wakeups->reproject($this->clock->now()),
      ] as $what => $call) {
        try {
          $call();
          self::fail("$what swallowed a failed write");
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
          throw $e;
        } catch (\RuntimeException $e) {
          self::assertStringContainsString('ddd_wakeups', $e->getMessage(), $what);
        }
      }
    } finally {
      $this->wpdb->suppress_errors($suppress);
    }
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

    [$w] = $this->wakeups->claim_due($this->clock->now(), 10, 60);
    self::assertSame('timeout:5:2', $w->intent->key);
    self::assertSame(WakeKind::Timeout, $w->intent->kind);
    self::assertSame([], $this->wakeups->claim_due($this->clock->now(), 10, 60), 'leased');

    [$w2] = $this->wakeups->claim_due($this->clock->now()->modify('+61 seconds'), 10, 60);
    self::assertFalse($this->wakeups->complete($w), 'lost lease');
    self::assertTrue($this->wakeups->retry_later($w2, 'busy', $this->clock->now()->modify('+2 minutes')));
    self::assertSame([], $this->wakeups->claim_due($this->clock->now()->modify('+61 seconds'), 10, 60));
    [$w3] = $this->wakeups->claim_due($this->clock->now()->modify('+2 minutes'), 10, 60);
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
    self::assertSame([$queued], $report->queued);
    self::assertSame([$running], array_map(static fn ($s) => $s->process_id, $report->running));
    self::assertSame(["continue:$bare:2"], array_column($this->intents(), 'idempotency_key'));
    self::assertEqualsCanonicalizing([['process_id' => $queued], ['process_id' => $bare]], array_column($this->pendingActions('ddd8it_process_continue'), 'args'), 'one action each, no duplicate for the 0.6-queued one');

    $again = (new WpStrandedScan($this->config, $store, $this->wakeups, $this->clock))->run();
    self::assertSame([], $again->minted, 'the minted intent is live now');
  }
}
