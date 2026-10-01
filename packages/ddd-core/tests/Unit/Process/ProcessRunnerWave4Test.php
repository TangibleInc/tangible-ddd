<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\AwaitedEventNotRegistered;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\AppDestroyScheduled;
use TangibleDDD\Core\Tests\Unit\Fixtures\ChildPurged;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AlarmProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\CancellableSyncProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\ChildrenFirstProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\EffectStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\FactOrDeadlineProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\KeyedJobProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\NoRetryProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\ReadinessProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\RetriedAwaitProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Process\AwaitRoute;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';
require_once dirname(__DIR__) . '/Fixtures/Process/Wave4Processes.php';

/**
 * Register section 8 wave 4 (core, process demands), on the mem doubles:
 * D3 keyed awaits (minted refs, persisted with the checkpoint before
 * dispatch), the register-then-check precheck, AwaitAny with cancellation
 * facts, AwaitAll over a checkpointed dynamic key set; D7 absolute-UTC
 * alarms of 24 h and more on durable intents (single delay); D1 inside
 * steps: the step retry policy (default 0 → compensate) with journal reuse.
 */
final class ProcessRunnerWave4Test extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d22';

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private SubscriptionRegistry $registry;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingCommand::$onSend = null;
    KeyedJobProcess::$undone = [];
    ReadinessProcess::$ready = false;
    ReadinessProcess::$seenStatus = [];
    ReadinessProcess::$statusProbe = null;
    EffectStepProcess::reset();

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->store->attachIntents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->lock = new InMemoryProcessLock();
    $this->registry = new SubscriptionRegistry();
    $this->runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, $this->registry, $this->boundary, $this->clock,
    );
    foreach ([JobFinished::class, AppDestroyScheduled::class, ChildPurged::class, UserJoined::class] as $fact) {
      $this->runner->register_event($fact);
    }
  }

  protected function tearDown(): void {
    RecordingCommand::$onSend = null;
    ReadinessProcess::$statusProbe = null;
    Correlation::reset();
    HostDefaults::resetForTests();
  }

  private function deliver(string $class, array $payload, string $eventId = self::EVENT_ID): void {
    (new IntegrationDelivery($this->registry, new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger()))
      ->deliver($class, IntegrationEnvelope::wrap($payload, 'corr-1', 1, $eventId));
  }

  /** @return list<WakeupIntent> */
  private function pendingOf(WakeKind $kind): array {
    return array_values(array_filter($this->wakeups->pending(), static fn (WakeupIntent $i) => $i->kind === $kind));
  }

  /** Claim and run every due intent once, as a drain would. */
  private function drainDue(): int {
    $n = 0;
    foreach ($this->wakeups->claimDue($this->clock->now(), 50, 60) as $claimed) {
      $this->runner->wake($claimed->intent);
      $this->boundary->run(fn () => $this->wakeups->complete($claimed));
      $n++;
    }
    return $n;
  }

  // ── D3: keyed awaits on a minted ref ──────────────────────────────────────

  public function test_step_ref_is_deterministic_per_process_step_and_purpose(): void {
    $p = new KeyedJobProcess();
    $this->runner->start($p);
    $job = RecordingCommand::$sent[0]->data;

    $copy = $this->store->find($p->get_id());
    self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $job);
    self::assertNotSame($job, $copy->step_ref('other'), 'the purpose is part of the name');
    // The suspended copy sits at the same step index: the same purpose mints the same ref.
    self::assertSame($job, $copy->step_ref('job'));
  }

  public function test_a_keyed_await_is_persisted_with_its_route_and_checkpoint_before_dispatch(): void {
    $seen = [];
    RecordingCommand::$onSend = function (RecordingCommand $c) use (&$seen): void {
      $copy = $this->store->find(1);
      $seen[] = [$copy->status(), $copy->await_routes(), $copy->checkpoint_for('order')?->text];
    };

    $this->runner->start(new KeyedJobProcess());

    $job = RecordingCommand::$sent[0]->data;
    self::assertSame('suspended', $seen[0][0]);
    self::assertEquals([new AwaitRoute(JobFinished::class, $job)], $seen[0][1]);
    self::assertSame($job, $seen[0][2], 'the step checkpoint commits with the await');
  }

  public function test_only_the_fact_carrying_the_minted_key_resumes_the_process(): void {
    $p = new KeyedJobProcess();
    $this->runner->start($p);
    $job = RecordingCommand::$sent[0]->data;

    $this->deliver(JobFinished::class, ['job_id' => 'someone-elses-job', 'ok' => true]);
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));

    $this->deliver(JobFinished::class, ['job_id' => $job, 'ok' => true]);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
    self::assertSame(['order:' . $job, 'record:ok', 'finish'], Journal::$steps);
  }

  public function test_a_duplicate_keyed_fact_is_a_quiet_no_op(): void {
    $p = new KeyedJobProcess();
    $this->runner->start($p);
    $job = RecordingCommand::$sent[0]->data;

    $this->deliver(JobFinished::class, ['job_id' => $job, 'ok' => true]);
    $report = $this->runner->resume_with_outcome(new JobFinished($job, true));

    self::assertTrue($report->isUnheard());
    self::assertSame(['order:' . $job, 'record:ok', 'finish'], Journal::$steps);
  }

  public function test_the_compensation_of_a_suspending_step_sees_its_checkpoint(): void {
    $p = new KeyedJobProcess();
    $this->runner->start($p);
    $job = RecordingCommand::$sent[0]->data;

    $this->deliver(JobFinished::class, ['job_id' => $job, 'ok' => false]);

    self::assertSame('failed', $this->store->statusOf($p->get_id()));
    self::assertSame([$job], KeyedJobProcess::$undone);
  }

  public function test_an_await_on_an_unregistered_fact_still_fails_loudly(): void {
    $runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, new SubscriptionRegistry(), $this->boundary, $this->clock,
    );
    $this->expectException(AwaitedEventNotRegistered::class);
    $runner->start(new KeyedJobProcess());
  }

  public function test_await_event_round_trips_its_key_and_timeout(): void {
    $a = AwaitEvent::keyed(JobFinished::class, 'job-1', ['ok' => true], 60, AwaitAll::TIMEOUT_PROCEED);
    $b = AwaitEvent::from_array($a->to_array());

    self::assertEquals($a, $b);
    self::assertTrue($b->accepts(new JobFinished('job-1', true)));
    self::assertFalse($b->accepts(new JobFinished('job-2', true)));
    self::assertFalse($b->accepts(new JobFinished('job-1', false)));
    self::assertSame(60, $b->timeout_seconds());
    // A 0.6 row (no key, no timeout) still decodes.
    self::assertEquals(new AwaitEvent(JobFinished::class), AwaitEvent::from_array(['event_class' => JobFinished::class]));
  }

  // ── D3: register-then-check precheck ──────────────────────────────────────

  public function test_the_precheck_runs_after_the_await_is_persisted_and_resumes_at_once(): void {
    ReadinessProcess::$ready = true;
    ReadinessProcess::$statusProbe = fn () => $this->store->statusOf(1);

    $p = new ReadinessProcess();
    $this->runner->start($p);

    self::assertSame(['suspended'], ReadinessProcess::$seenStatus, 'registered first, then checked');
    self::assertSame(['await_ready', 'provision:string'], Journal::$steps);
    self::assertSame(['ask-ready'], RecordingCommand::labels(), 'the step commands dispatched before the check');
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
    self::assertSame([], $this->pendingOf(WakeKind::Timeout), 'the alarm was cancelled with the resuming save');
  }

  public function test_a_late_fact_after_a_satisfied_precheck_is_absorbed(): void {
    ReadinessProcess::$ready = true;
    // Capture the minted key while the await is registered (the precheck runs then).
    ReadinessProcess::$statusProbe = fn () => $this->store->find(1)->await_routes()[0]->awaitKey;
    $p = new ReadinessProcess();
    $this->runner->start($p);
    [$minted] = ReadinessProcess::$seenStatus;

    // The fact the precheck stood in for arrives late, keyed on the minted ref.
    $report = $this->runner->resume_with_outcome(new JobFinished((string) $minted, true));

    self::assertTrue($report->isUnheard(), 'no process waits for it any more, and nothing throws');
    self::assertSame([], $report->resumed);
    self::assertSame(['await_ready', 'provision:string'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_an_unsatisfied_precheck_stays_suspended_and_the_fact_resumes_later(): void {
    $p = new ReadinessProcess();
    $this->runner->start($p);
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));

    $ref = $this->store->find($p->get_id())->await_routes()[0]->awaitKey;
    $this->deliver(JobFinished::class, ['job_id' => $ref, 'ok' => true]);

    self::assertSame(['await_ready', 'provision:' . JobFinished::class], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_the_precheck_is_skipped_when_a_fact_already_resumed_the_process_during_dispatch(): void {
    ReadinessProcess::$ready = true;
    RecordingCommand::$onSend = function (RecordingCommand $c): void {
      $ref = $this->store->find(1)->await_routes()[0]->awaitKey;
      $this->runner->resume(new JobFinished($ref, true));
    };

    $this->runner->start(new ReadinessProcess());

    self::assertSame([], ReadinessProcess::$seenStatus, 'the nested resume moved the process on');
    self::assertSame(['await_ready', 'provision:' . JobFinished::class], Journal::$steps);
  }

  // ── D3: any-of with cancellation facts ───────────────────────────────────

  public function test_any_of_resumes_on_the_answer(): void {
    $p = new CancellableSyncProcess(9);
    $this->runner->start($p);
    $job = RecordingCommand::$sent[0]->data;

    $this->deliver(JobFinished::class, ['job_id' => $job, 'ok' => true]);

    self::assertSame(['prepare:9', 'sync:9', 'complete_sync:9'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_a_cancellation_fact_compensates_every_process_it_cancels(): void {
    $a = new CancellableSyncProcess(9);
    $b = new CancellableSyncProcess(9);
    $other = new CancellableSyncProcess(10);
    $this->runner->start($a);
    $this->runner->start($b);
    $this->runner->start($other);
    Journal::reset();

    $this->deliver(AppDestroyScheduled::class, ['app_id' => 9]);

    self::assertSame('failed', $this->store->statusOf($a->get_id()));
    self::assertSame('failed', $this->store->statusOf($b->get_id()));
    self::assertSame('suspended', $this->store->statusOf($other->get_id()));
    self::assertCount(2, Journal::$steps);
    self::assertStringStartsWith('unprepare:9:', Journal::$steps[0]);
    self::assertStringContainsString('Cancelled by', Journal::$steps[0]);
  }

  public function test_any_of_is_found_by_every_branch_class_on_a_single_column_store(): void {
    $p = new CancellableSyncProcess(9);
    $this->runner->start($p);

    self::assertSame([$p->get_id()], $this->store->findWaitingFor(JobFinished::class));
    self::assertSame([$p->get_id()], $this->store->findWaitingFor(AppDestroyScheduled::class));

    // A fact of another class may reach it through the common-ancestor
    // column, but accepts() filters it: nothing resumes.
    $report = $this->runner->resume_with_outcome(new UserJoined(9));
    self::assertTrue($report->isUnheard());
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));
  }

  public function test_any_of_round_trips_and_routes_each_branch(): void {
    $any = AwaitAny::of(AwaitEvent::keyed(JobFinished::class, 'j1'))
      ->cancelledBy(new AwaitEvent(AppDestroyScheduled::class, ['app_id' => 3]))
      ->until(new \DateTimeImmutable('2026-10-03 00:00:00', new \DateTimeZone('Europe/Berlin')));
    $copy = AwaitAny::from_array(json_decode(json_encode($any->to_array()), true));

    self::assertEquals($any, $copy);
    self::assertEquals([new AwaitRoute(JobFinished::class, 'j1'), new AwaitRoute(AppDestroyScheduled::class, '')], $copy->routes());
    self::assertSame('2026-10-02T22:00:00+00:00', $copy->deadline()->format('c'));
    $cancelled = $copy->accumulate(new AppDestroyScheduled(3));
    self::assertTrue($cancelled->is_satisfied());
    self::assertNotNull($cancelled->cancellation_reason(new AppDestroyScheduled(3)));
    self::assertNull($copy->accumulate(new JobFinished('j1'))->cancellation_reason(new JobFinished('j1')));
  }

  // ── D3: AwaitAll over a checkpointed dynamic key set ──────────────────────

  public function test_dynamic_await_all_resumes_when_every_checkpointed_key_arrived(): void {
    $p = new ChildrenFirstProcess(['c1', 'c2', 'c3']);
    $this->runner->start($p);

    $copy = $this->store->find($p->get_id());
    self::assertSame(['c1', 'c2', 'c3'], $copy->checkpoint_for('children_first')->items);
    self::assertEquals(
      [new AwaitRoute(ChildPurged::class, 'c1'), new AwaitRoute(ChildPurged::class, 'c2'), new AwaitRoute(ChildPurged::class, 'c3')],
      $copy->await_routes()
    );

    $this->deliver(ChildPurged::class, ['child_id' => 'c2']);
    self::assertEquals(
      [new AwaitRoute(ChildPurged::class, 'c1'), new AwaitRoute(ChildPurged::class, 'c3')],
      $this->store->find($p->get_id())->await_routes(),
      'the routes shrink as keys arrive'
    );
    $this->deliver(ChildPurged::class, ['child_id' => 'c9']);
    $this->deliver(ChildPurged::class, ['child_id' => 'c1'], '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d23');
    $this->deliver(ChildPurged::class, ['child_id' => 'c3'], '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d24');

    self::assertSame(['children_first:3', 'purge_self:c2,c1,c3'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_an_empty_dynamic_key_set_does_not_suspend(): void {
    $p = new ChildrenFirstProcess([]);
    $this->runner->start($p);

    self::assertSame(['children_first:0', 'purge_self:'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
    self::assertSame([], $this->pendingOf(WakeKind::Timeout));
  }

  public function test_await_all_keyed_round_trips(): void {
    $all = AwaitAll::keyed(ChildPurged::class, ['a', 'b'], 60)->accumulate(new ChildPurged('a'));
    $copy = AwaitAll::from_array(json_decode(json_encode($all->to_array()), true));

    self::assertEquals($all, $copy);
    self::assertTrue($copy->accepts(new ChildPurged('b')));
    self::assertFalse($copy->accepts(new ChildPurged('a')), 'already gathered');
  }

  // ── D7: absolute-UTC alarms on durable intents ────────────────────────────

  public function test_a_25_hour_alarm_fires_once_at_its_absolute_due_time(): void {
    // process.alarm-long (mem)
    $p = new AlarmProcess(null, 25 * 3600);
    $this->runner->start($p);

    [$intent] = $this->pendingOf(WakeKind::Timeout);
    self::assertSame('2026-10-02T13:00:00+00:00', $intent->dueAt->format('c'));
    self::assertSame('2026-10-02T13:00:00+00:00', $this->store->find($p->get_id())->await_deadline()?->format('c'));
    self::assertNull($this->store->find($p->get_id())->waiting_for(), 'a timer waits for no fact');

    // A restarted worker (a fresh runner on the same rows) sees nothing due before then.
    $this->runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, $this->registry, $this->boundary, $this->clock,
    );
    $this->clock->advance('PT24H59M59S');
    self::assertSame(0, $this->drainDue());

    $this->clock->advance('PT1S');
    self::assertSame(1, $this->drainDue());
    $this->clock->advance('PT48H');
    self::assertSame(0, $this->drainDue(), 'no second delay, no re-arm');
    $this->runner->wake($intent); // a surviving duplicate of the wake

    self::assertSame(['wait', 'fire'], Journal::$steps);
    self::assertSame(['fired'], RecordingCommand::labels());
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_an_alarm_at_an_absolute_instant_is_due_exactly_then_in_utc(): void {
    $p = new AlarmProcess('2026-10-04T09:30:00-04:00');
    $this->runner->start($p);

    [$intent] = $this->pendingOf(WakeKind::Timeout);
    self::assertSame('2026-10-04T13:30:00+00:00', $intent->dueAt->format('c'));
  }

  public function test_alarm_round_trips(): void {
    $at = AwaitAlarm::at(new \DateTimeImmutable('2026-10-09T00:00:00+02:00'));
    self::assertEquals($at, AwaitAlarm::from_array(json_decode(json_encode($at->to_array()), true)));
    self::assertSame('2026-10-08T22:00:00+00:00', $at->deadline()->format('c'));
    $after = AwaitAlarm::after(3 * 86400);
    self::assertEquals($after, AwaitAlarm::from_array($after->to_array()));
    self::assertNull($after->deadline(), 'relative: the runner fixes the instant once, at suspension');
    self::assertSame(3 * 86400, $after->timeout_seconds());
  }

  public function test_a_fact_or_a_deadline_whichever_comes_first(): void {
    $deadline = new FactOrDeadlineProcess('2026-10-04T12:00:00+00:00');
    $this->runner->start($deadline);
    [$intent] = $this->pendingOf(WakeKind::Timeout);
    self::assertSame('2026-10-04T12:00:00+00:00', $intent->dueAt->format('c'));

    $this->clock->advance('PT72H');
    $this->drainDue();
    self::assertSame(['wait', 'after:deadline'], Journal::$steps);

    Journal::reset();
    $fact = new FactOrDeadlineProcess('2026-10-09T12:00:00+00:00');
    $this->runner->start($fact);
    $this->deliver(AppDestroyScheduled::class, ['app_id' => 1]);
    self::assertSame(['wait', 'after:fact'], Journal::$steps);
    self::assertSame([], $this->pendingOf(WakeKind::Timeout), 'the deadline was cancelled with the resuming save');
  }

  // ── D1 inside steps: step retry policy with journal reuse ─────────────────

  public function test_a_failed_step_is_retried_by_its_policy_and_reuses_the_journaled_effect(): void {
    EffectStepProcess::reset(recordFailures: 1);
    $p = new EffectStepProcess();
    $this->runner->start($p);

    self::assertSame('scheduled', $this->store->statusOf($p->get_id()), 'a retry, not a compensation');
    [$retry] = $this->pendingOf(WakeKind::Continue);
    self::assertSame('2026-10-01T12:00:30+00:00', $retry->dueAt->format('c'), 'the policy backoff');
    self::assertSame(1, $this->store->find($p->get_id())->step_attempts('charge'));

    $this->clock->advance('PT30S');
    $this->drainDue();

    self::assertSame(1, EffectStepProcess::$performed, 'the process-level retry reused the journaled result');
    self::assertSame(['charge', 'charge', 'done'], Journal::$steps);
    self::assertSame(EffectStepProcess::$keys[0], EffectStepProcess::$keys[1], 'same idempotency key on the re-run');
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_an_exhausted_retry_policy_compensates(): void {
    EffectStepProcess::reset(recordFailures: 5);
    $p = new EffectStepProcess();
    $this->runner->start($p);
    for ($i = 0; $i < 3; $i++) {
      $this->clock->advance('PT30S');
      $this->drainDue();
    }

    self::assertSame(['charge', 'charge', 'charge'], Journal::$steps, '1 attempt + 2 retries');
    self::assertSame('failed', $this->store->statusOf($p->get_id()));
  }

  public function test_the_default_policy_is_zero_retries_then_compensate(): void {
    $p = new NoRetryProcess();
    $this->runner->start($p);

    self::assertSame(['first', 'second', 'undo_first'], Journal::$steps);
    self::assertSame('failed', $this->store->statusOf($p->get_id()));
    self::assertSame([], $this->pendingOf(WakeKind::Continue));
  }

  public function test_a_retried_suspending_step_withdraws_its_await_first(): void {
    RecordingCommand::$onSend = static function (RecordingCommand $c): void {
      static $failed = false;
      if (!$failed) {
        $failed = true;
        throw new \RuntimeException('transport down');
      }
    };
    $p = new RetriedAwaitProcess();
    $this->runner->start($p);

    self::assertSame('scheduled', $this->store->statusOf($p->get_id()));
    self::assertSame([], $this->store->findWaitingFor(JobFinished::class), 'the await was withdrawn');

    $this->drainDue();
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));
    self::assertSame(['ask', 'ask'], Journal::$steps);
    self::assertSame(RecordingCommand::$hints[0], RecordingCommand::$hints[1], 'the re-run dispatches the same deterministic command id');
  }

  public function test_new_step_state_round_trips_through_the_steps_json(): void {
    $steps = new ProcessSteps(['a', 'b'], [], [], 1, -1, null, ['a' => 2], '2026-10-02T13:00:00+00:00');
    $copy = ProcessSteps::from_json(json_decode((string) $steps->to_json(), false));

    self::assertSame(['a' => 2], $copy->attempts);
    self::assertSame('2026-10-02T13:00:00+00:00', $copy->await_due_at);
    // A 0.6 / wave-3 row without the new keys still decodes.
    $old = ProcessSteps::from_json(json_decode('{"steps":["a"],"compensations":{},"checkpoints":{},"step_index":0,"undo_index":-1,"failure_msg":null}', false));
    self::assertSame([], $old->attempts);
    self::assertNull($old->await_due_at);
  }
}
