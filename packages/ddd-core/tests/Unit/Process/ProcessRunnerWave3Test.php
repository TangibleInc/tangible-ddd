<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\ProcessStartedInsideCommand;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AskThenWaitProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AsyncHopProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AwaitingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\IgnitedProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TimedGatherProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IStrandedScanner;
use TangibleDDD\Runtime\Scheduling\IWakeHandler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * Register section 8 wave 3 (core): the ProcessRunner behind the ports with
 * re-read under the lock, await before dispatch (F2), one-transaction state
 * changes, ResumeRetry on contention, the fenced touch before each step
 * dispatch, ignition through insert_ignited, the stranded scan and the
 * deferred start mode. All on the mem doubles.
 */
final class ProcessRunnerWave3Test extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private SubscriptionRegistry $registry;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingCommand::$onSend = null;

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->store->attach_intents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->lock = new InMemoryProcessLock();
    $this->registry = new SubscriptionRegistry();
    $this->runner = $this->runner();
  }

  protected function tearDown(): void {
    RecordingCommand::$onSend = null;
    Correlation::reset();
    HostDefaults::reset_for_tests();
  }

  private function runner(?IProcessLock $lock = null, ?StartMode $mode = null): ProcessRunner {
    return new ProcessRunner(
      new AcmeConfig(), null, $lock ?? $this->lock, $this->store, $this->wakeups, $this->registry,
      $this->boundary, $this->clock, $mode,
    );
  }

  private function deliver(string $class, array $payload, ?InMemoryDeliveryLedger $ledger = null): \TangibleDDD\Runtime\Delivery\DeliveryOutcome {
    return (new IntegrationDelivery($this->registry, $ledger ?? new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger()))
      ->deliver($class, IntegrationEnvelope::wrap($payload, 'corr-1', 1, self::EVENT_ID));
  }

  /** @return list<WakeupIntent> */
  private function pendingOf(WakeKind $kind): array {
    return array_values(array_filter($this->wakeups->pending(), static fn (WakeupIntent $i) => $i->kind === $kind));
  }

  public function test_the_runner_is_the_wake_handler_and_the_stranded_scanner(): void {
    self::assertInstanceOf(IWakeHandler::class, $this->runner);
    self::assertInstanceOf(IStrandedScanner::class, $this->runner);
  }

  // ── F2: await before dispatch ─────────────────────────────────────────────

  public function test_the_await_is_persisted_before_the_steps_commands_dispatch(): void {
    $this->runner->register_event(UserJoined::class);
    $seen = [];
    RecordingCommand::$onSend = function (RecordingCommand $c) use (&$seen): void {
      $seen[$c->label] = $this->store->status_of(1);
    };

    $this->runner->start(new AskThenWaitProcess());

    self::assertSame(['ask' => 'suspended', 'ask-2' => 'suspended'], $seen);
  }

  public function test_an_awaited_fact_delivered_inside_the_dispatch_still_resumes_the_process(): void {
    // process.await-before-dispatch
    $this->runner->register_event(UserJoined::class);
    RecordingCommand::$onSend = function (RecordingCommand $c): void {
      if ($c->label === 'ask') {
        $this->runner->resume(new UserJoined(5));
      }
    };

    $p = new AskThenWaitProcess();
    $this->runner->start($p);

    self::assertSame(['ask', 'thank'], Journal::$steps);
    self::assertSame(['ask', 'thank', 'ask-2'], RecordingCommand::labels());
    self::assertSame('completed', $this->store->status_of($p->get_id()));
    self::assertSame(0, $this->lock->held_count());
  }

  public function test_a_command_failing_after_the_suspension_compensates_and_unregisters_the_await(): void {
    $this->runner->register_event(UserJoined::class);
    RecordingCommand::$onSend = static function (RecordingCommand $c): void {
      if ($c->label === 'ask-2') {
        throw new \RuntimeException('mailer down');
      }
    };

    $p = new AskThenWaitProcess();
    $this->runner->start($p);
    RecordingCommand::$onSend = null;
    $this->runner->resume(new UserJoined(5));

    // The failing step itself is not compensated (only completed steps are),
    // and the persisted await was withdrawn: the later fact finds nothing.
    self::assertSame(['ask'], Journal::$steps, 'compensated; a later fact does not resurrect it');
    self::assertSame('failed', $this->store->status_of($p->get_id()));
    self::assertSame([], $this->store->find_waiting_for(UserJoined::class));
  }

  // ── re-read under the lock, fence ─────────────────────────────────────────

  /** A lock that runs $before once, just before its next acquisition (an interloper between read and lock). */
  private function interloperLock(\Closure $before): IProcessLock {
    return new class($this->lock, $before) implements IProcessLock {
      public function __construct(private IProcessLock $inner, private ?\Closure $before) {}
      public function arm(\Closure $before): void { $this->before = $before; }
      public function acquire(LockKey $k, float $t): LockHandle {
        if ($this->before !== null) {
          $b = $this->before;
          $this->before = null;
          $b($k);
        }
        return $this->inner->acquire($k, $t);
      }
      public function release(LockHandle $h): void { $this->inner->release($h); }
      public function held_count(): int { return $this->inner->held_count(); }
      public function release_all(): int { return $this->inner->release_all(); }
    };
  }

  public function test_resume_re_reads_under_the_lock(): void {
    $lock = $this->interloperLock(static fn () => null);
    $runner = $this->runner($lock);
    $runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $runner->start($p);
    $id = $p->get_id();

    // Between the runner's unlocked pre-read and its lock, another worker
    // finishes the process.
    $store = $this->store;
    $lock->arm(static function () use ($store, $id): void {
      $other = $store->find($id);
      $other->complete();
      $store->save($other, (int) $store->version_of($id));
    });

    $runner->resume(new UserJoined(5));

    self::assertSame(['invite'], Journal::$steps, 'the re-read under the lock saw completed: no step');
    self::assertSame('completed', $this->store->status_of($id));
  }

  public function test_the_fenced_touch_aborts_before_the_steps_commands_dispatch(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $id = $p->get_id();

    $store = $this->store;
    Journal::$onNote = static function (string $step) use ($store, $id): void {
      if ($step === 'greet') {
        $store->touch($id, (int) $store->version_of($id)); // the row moved under us mid-step
      }
    };

    try {
      $this->runner->resume(new UserJoined(5));
      self::fail('expected the fence to abort the wake');
    } catch (ConcurrentProcessModification) {
    }

    self::assertSame(['invite'], RecordingCommand::labels(), 'greet ran but its command was never dispatched');
    self::assertNotSame('failed', $this->store->status_of($id), 'lost ownership is not a business failure');
    self::assertSame(0, $this->lock->held_count());
  }

  // ── one transaction per state change; timeouts ────────────────────────────

  public function test_the_timeout_intent_is_cancelled_with_the_resuming_save(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();
    $this->runner->start($p);
    self::assertCount(1, $this->pendingOf(WakeKind::Timeout));

    $this->deliver(UserJoined::class, ['user_id' => 1]);
    self::assertCount(1, $this->pendingOf(WakeKind::Timeout), 'a partial gather keeps its alarm');

    $this->deliver(UserJoined::class, ['user_id' => 2]);
    self::assertSame([], $this->pendingOf(WakeKind::Timeout));
    self::assertSame(['prepare', 'gather', 'report'], Journal::$steps);
  }

  public function test_timeout_first_then_fact_never_resurrects_the_process(): void {
    // process.timeout-vs-event
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess(AwaitAll::TIMEOUT_FAIL);
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), 1);
    $this->deliver(UserJoined::class, ['user_id' => 1]);
    $this->deliver(UserJoined::class, ['user_id' => 2]);

    self::assertSame(['prepare', 'gather', 'undo_prepare'], Journal::$steps);
    self::assertSame('failed', $this->store->status_of($p->get_id()));
  }

  public function test_fact_first_then_the_stale_timeout_is_a_noop(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess(AwaitAll::TIMEOUT_FAIL);
    $this->runner->start($p);
    $this->deliver(UserJoined::class, ['user_id' => 1]);
    $this->deliver(UserJoined::class, ['user_id' => 2]);

    $this->runner->wake(WakeupIntent::timeout('acme', $p->get_id(), 1, $this->clock->now()));

    self::assertSame(['prepare', 'gather', 'report'], Journal::$steps);
    self::assertSame('completed', $this->store->status_of($p->get_id()));
  }

  public function test_a_continuation_is_stale_safe(): void {
    // process.stale-wakeup
    (fn () => $this->max_execution_seconds = 0)->call($this->runner);
    $p = new TwoStepProcess(3);
    $this->runner->start($p);
    self::assertSame('scheduled', $this->store->status_of($p->get_id()));

    $this->runner->continue_scheduled($p->get_id(), 0);   // a step already passed
    self::assertSame(['reserve'], Journal::$steps);

    $this->runner->wake(WakeupIntent::continuation('acme', $p->get_id(), 1, $this->clock->now()));
    $this->runner->wake(WakeupIntent::continuation('acme', $p->get_id(), 1, $this->clock->now()));
    self::assertSame(['reserve', 'ship'], Journal::$steps, 'the duplicate continuation is a no-op');
    self::assertSame('completed', $this->store->status_of($p->get_id()));
  }

  public function test_an_async_step_runs_after_exactly_one_continuation(): void {
    $p = new AsyncHopProcess();
    $this->runner->start($p);
    self::assertSame(['before'], Journal::$steps);
    [$intent] = $this->pendingOf(WakeKind::Continue);

    $this->runner->wake($intent);

    self::assertSame(['before', 'after'], Journal::$steps);
    self::assertSame('completed', $this->store->status_of($p->get_id()));
  }

  // ── ResumeRetry on contention ─────────────────────────────────────────────

  public function test_a_contended_timeout_is_re_queued_as_a_resume_retry_and_later_succeeds(): void {
    // lock.contention (extraction branch: "later succeeds")
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess(AwaitAll::TIMEOUT_FAIL);
    $this->runner->start($p);
    $id = $p->get_id();
    $version = $this->store->version_of($id);
    $this->lock->hold_elsewhere(new LockKey('acme', '', $id));

    try {
      $this->runner->handle_timeout($id, 1);
      self::fail('expected the lock failure to surface');
    } catch (LockingException) {
    }

    self::assertSame($version, $this->store->version_of($id), 'row unchanged');
    $retries = $this->pendingOf(WakeKind::ResumeRetry);
    self::assertCount(1, $retries);
    self::assertSame('suspended', $retries[0]->expected_status);
    self::assertSame(1, $retries[0]->step_index);
    self::assertEquals($this->clock->now()->modify('+2 seconds'), $retries[0]->due_at, 'wake backoff 2 s × 2^0');

    $this->lock->release_elsewhere(new LockKey('acme', '', $id));
    $this->runner->wake($retries[0]);

    self::assertSame(['prepare', 'gather', 'undo_prepare'], Journal::$steps);
  }

  public function test_a_contended_in_band_start_is_re_queued_and_the_retry_runs_the_first_step_once(): void {
    $this->lock->hold_elsewhere(new LockKey('acme', '', 1));
    try {
      $this->runner->start(new TwoStepProcess(4));
      self::fail('expected the lock failure to surface');
    } catch (LockingException) {
    }
    self::assertSame([], Journal::$steps);
    [$retry] = $this->pendingOf(WakeKind::ResumeRetry);
    self::assertSame('running', $retry->expected_status);
    self::assertSame(1, $retry->retry_version());

    $this->lock->release_elsewhere(new LockKey('acme', '', 1));
    $this->runner->wake($retry);
    $this->runner->wake($retry);

    self::assertSame(['reserve', 'ship'], Journal::$steps, 'the second wake is stale (the row version moved)');
  }

  public function test_a_contended_continuation_is_re_queued(): void {
    (fn () => $this->max_execution_seconds = 0)->call($this->runner);
    $p = new TwoStepProcess(3);
    $this->runner->start($p);
    $this->lock->hold_elsewhere(new LockKey('acme', '', $p->get_id()));

    try {
      $this->runner->continue_scheduled($p->get_id());
    } catch (LockingException) {
    }

    [$retry] = $this->pendingOf(WakeKind::ResumeRetry);
    self::assertSame('scheduled', $retry->expected_status);
    $this->lock->release_elsewhere(new LockKey('acme', '', $p->get_id()));
    (fn () => $this->max_execution_seconds = 25)->call($this->runner);
    $this->runner->wake($retry);
    self::assertSame(['reserve', 'ship'], Journal::$steps);
  }

  public function test_contention_inside_wake_propagates_without_a_second_retry(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();
    $this->runner->start($p);
    $this->lock->hold_elsewhere(new LockKey('acme', '', $p->get_id()));

    try {
      $this->runner->wake(WakeupIntent::timeout('acme', $p->get_id(), 1, $this->clock->now()));
      self::fail('expected the lock failure to surface');
    } catch (LockingException) {
    }

    self::assertSame([], $this->pendingOf(WakeKind::ResumeRetry), 'the drain re-queues the claimed intent itself');
  }

  public function test_a_contended_fact_resume_is_parked_as_a_resume_retry_carrying_the_fact(): void {
    // Wave 5 (AW2) inverted the wave-3 rule on a scheduler that carries
    // facts: the subscriber no longer fails, the fact rides a ResumeRetry.
    // A scheduler without ICarriesFacts keeps the delivery retry
    // (ProcessRunnerWave5Test).
    $this->runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $ledger = new InMemoryDeliveryLedger();
    $this->lock->hold_elsewhere(new LockKey('acme', '', $p->get_id()));

    $first = $this->deliver(UserJoined::class, ['user_id' => 5], $ledger);
    self::assertSame([], $first->failed, 'no delivery attempt spent');
    self::assertSame('suspended', $this->store->status_of($p->get_id()));
    [$retry] = $this->pendingOf(WakeKind::ResumeRetry);
    self::assertSame(self::EVENT_ID, $retry->fact['event_id'] ?? null);

    $this->lock->release_elsewhere(new LockKey('acme', '', $p->get_id()));
    $this->runner->wake($retry);
    self::assertSame('completed', $this->store->status_of($p->get_id()));
  }

  public function test_wake_refuses_a_deliver_intent(): void {
    $this->expectException(\LogicException::class);
    $this->runner->wake(new WakeupIntent(WakeKind::Deliver, 'acme', null, null, null, $this->clock->now(), 'deliver:x'));
  }

  // ── ignition ──────────────────────────────────────────────────────────────

  public function test_ignition_contention_after_the_insert_is_re_queued_and_a_redelivery_does_not_ignite_twice(): void {
    $this->lock->hold_elsewhere(new LockKey('acme', '', 1));

    try {
      $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(4), self::EVENT_ID);
    } catch (LockingException) {
    }
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(4), self::EVENT_ID); // the delivery retry

    self::assertSame(1, $this->store->count());
    self::assertSame([], Journal::$steps);
    $this->lock->release_elsewhere(new LockKey('acme', '', 1));
    [$retry] = $this->pendingOf(WakeKind::ResumeRetry);
    $this->runner->wake($retry);
    self::assertSame(['open:4'], Journal::$steps);
  }

  public function test_manual_starts_in_a_drain_are_never_deduped_and_the_starts_on_ignition_still_ignites_once(): void {
    // process.manual-start-in-drain
    Correlation::within((new TraceContext('c'))->for_fact(self::EVENT_ID), function (): void {
      $this->runner->start(new IgnitedProcess(1));
      $this->runner->start(new IgnitedProcess(1));
    });
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(1), self::EVENT_ID);
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(1), self::EVENT_ID);

    self::assertSame(3, $this->store->count());
    self::assertNull($this->store->ignition_key_of(1));
    self::assertNull($this->store->ignition_key_of(2));
    self::assertNotNull($this->store->ignition_key_of(3));
    foreach ([1, 2, 3] as $id) {
      self::assertSame(self::EVENT_ID, $this->store->find($id)->ignited_by_event_id());
    }
  }

  // ── deferred start (sf default) ───────────────────────────────────────────

  public function test_a_deferred_start_persists_and_writes_a_continue_intent_without_locking(): void {
    $runner = $this->runner(mode: StartMode::Deferred);
    $p = new TwoStepProcess(2);

    $runner->start($p);

    self::assertSame([], Journal::$steps, 'no step runs in the request');
    self::assertSame(0, $this->lock->acquisitions(), 'no lock is taken');
    self::assertSame('scheduled', $this->store->status_of($p->get_id()));
    [$intent] = $this->pendingOf(WakeKind::Continue);
    self::assertSame("continue:{$p->get_id()}:0", $intent->key);

    $runner->wake($intent);
    self::assertSame(['reserve', 'ship'], Journal::$steps);
  }

  public function test_the_host_can_choose_the_start_mode_for_every_runner(): void {
    HostDefaults::provide(StartMode::class, StartMode::Deferred);

    $this->runner->start(new TwoStepProcess(2));

    self::assertSame([], Journal::$steps);
    self::assertSame('scheduled', $this->store->status_of(1));
  }

  public function test_a_deferred_start_commits_with_the_callers_transaction(): void {
    $runner = $this->runner(mode: StartMode::Deferred);

    try {
      $this->boundary->run(function () use ($runner): void {
        $runner->start(new TwoStepProcess(2));
        throw new \DomainException('caller rolls back');
      });
    } catch (\DomainException) {
    }

    self::assertSame(0, $this->store->count());
    self::assertSame([], $this->wakeups->pending());
  }

  public function test_a_deferred_start_is_legal_inside_a_command_and_an_in_band_one_is_not(): void {
    $act = (new TraceContext('c'))->for_act('cmd-1', 'PlaceOrder');
    $deferred = $this->runner(mode: StartMode::Deferred);

    Correlation::within($act, fn () => $this->boundary->run(fn () => $deferred->start(new TwoStepProcess(1))));
    self::assertSame(1, $this->store->count());

    $this->expectException(ProcessStartedInsideCommand::class);
    Correlation::within($act, fn () => $this->runner->start(new TwoStepProcess(1)));
  }

  // ── stranded scan ─────────────────────────────────────────────────────────

  public function test_the_stranded_scan_requeues_scheduled_rows_and_reports_running_ones(): void {
    // A scheduled process whose intent was lost, and a running one whose worker died.
    $runner = $this->runner(mode: StartMode::Deferred);
    $scheduled = new TwoStepProcess(1);
    $runner->start($scheduled);
    foreach ($this->wakeups->claim_due($this->clock->now(), 10, 60) as $w) {
      $this->wakeups->complete($w); // the intent is gone, the row stays scheduled
    }
    $running = new TwoStepProcess(2);
    $this->boundary->run(fn () => $this->store->insert($running)); // `pending`→ a raw running row
    $copy = $this->store->find($running->get_id());
    $copy->advance(status: 'running');
    $this->store->save($copy, 1);
    $this->clock->advance('+16 minutes');

    $report = $this->runner->scan_stranded($this->clock->now());

    self::assertSame([$scheduled->get_id()], $report->requeued);
    self::assertCount(1, $report->reported);
    self::assertSame($running->get_id(), $report->reported[0]->process_id);
    [$intent] = $this->pendingOf(WakeKind::Continue);
    self::assertSame($scheduled->get_id(), $intent->process_id);
    self::assertSame([], $this->runner->scan_stranded($this->clock->now())->requeued, 'a live intent is not stranded');

    $this->runner->wake($intent);
    self::assertSame(['reserve', 'ship'], Journal::$steps);
  }

  // ── deterministic step command ids ────────────────────────────────────────

  public function test_step_commands_carry_deterministic_ids(): void {
    $p = new TwoStepProcess(7);
    $this->runner->start($p);

    self::assertSame([
      DeterministicCommandId::for_step('acme', $p->get_id(), 0, 0),
      DeterministicCommandId::for_step('acme', $p->get_id(), 1, 0),
    ], RecordingCommand::$hints);
    self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', RecordingCommand::$hints[0]);
    self::assertNotSame(RecordingCommand::$hints[0], DeterministicCommandId::for_step('other', $p->get_id(), 0, 0));
  }
}
