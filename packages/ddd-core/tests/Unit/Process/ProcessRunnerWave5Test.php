<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessLockUnavailable;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\ResumeSource;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\ArrayProcessRepository;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\LegacyProcessStore;
use TangibleDDD\Testing\InMemoryNamedLock;
use TangibleDDD\Testing\StaticConsumerIdentity;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AwaitingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\CauseReadingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\MemberJoined;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\ParentClassWaitProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\VipJoined;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryParkingScheduler;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';
require_once dirname(__DIR__) . '/Fixtures/Process/Wave4Processes.php';
require_once dirname(__DIR__) . '/Fixtures/Process/Wave5Processes.php';

/**
 * Wave 5, TXP process-kernel demands on the mem doubles:
 *
 * - AW2: a fact resume that cannot take the process lock does not spend the
 *   answer's delivery budget. It writes a ResumeRetry wakeup carrying the
 *   fact and the subscriber succeeds; the wakeup retries on its own budget
 *   (never dropped) until the lock is free.
 * - AW1 (D13): a post-await step reads the event id of the fact that
 *   resumed it, also on a #[RetryStep] re-run and in a deferred resume.
 */
final class ProcessRunnerWave5Test extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d51';
  private const OTHER_EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d52';
  private const BUDGET = 5;

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private SubscriptionRegistry $registry;
  private InMemoryDeliveryLedger $ledger;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingCommand::$onSend = null;
    CauseReadingProcess::$failures = 0;

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryParkingScheduler($this->boundary);
    $this->store->attach_intents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->lock = new InMemoryProcessLock();
    $this->registry = new SubscriptionRegistry();
    $this->ledger = new InMemoryDeliveryLedger();
    $this->runner = $this->runner($this->wakeups);
    $this->runner->register_event(UserJoined::class);
    $this->runner->register_event(JobFinished::class);
  }

  protected function tearDown(): void {
    RecordingCommand::$onSend = null;
    Correlation::reset();
    HostDefaults::reset_for_tests();
  }

  private function runner(IWakeupScheduler $wakeups, ?IProcessStore $store = null): ProcessRunner {
    return new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $store ?? $this->store, $wakeups, $this->registry, $this->boundary, $this->clock,
    );
  }

  private function deliver(string $class, array $payload, string $event_id = self::EVENT_ID): DeliveryOutcome {
    return (new IntegrationDelivery($this->registry, $this->ledger, self::BUDGET, new NullLogger()))
      ->deliver($class, IntegrationEnvelope::wrap($payload, 'corr-1', 1, $event_id));
  }

  private function drain(): DrainReport {
    return (new Drain(wakeups: $this->wakeups, processWakes: $this->runner, clock: $this->clock, logger: new NullLogger()))->run_once();
  }

  /** @return list<WakeupIntent> */
  private function retries(): array {
    return array_values(array_filter($this->wakeups->pending(), static fn (WakeupIntent $i) => $i->kind === WakeKind::ResumeRetry));
  }

  private function hold(int $process_id): void {
    $this->lock->hold_elsewhere(new LockKey('acme', '', $process_id));
  }

  private function release(int $process_id): void {
    $this->lock->release_elsewhere(new LockKey('acme', '', $process_id));
  }

  private function resume_subscriber(string $class): string {
    return 'acme/resume:' . $class;
  }

  // ── AW2 ───────────────────────────────────────────────────────────────────

  public function test_fact_carrying_is_an_opt_in_on_the_mem_scheduler(): void {
    self::assertInstanceOf(ICarriesFacts::class, $this->wakeups);
    self::assertNotInstanceOf(ICarriesFacts::class, new InMemoryWakeupScheduler($this->boundary));
    self::assertInstanceOf(InMemoryParkingScheduler::class, InMemoryParkingScheduler::lenient());
  }

  /**
   * The mem reproduction of TXP ProcessLockContentionTest's "an answer held
   * off longer than the delivery budget": inverted, the answer is never
   * dead-lettered and the process resumes once the lock is free.
   */
  public function test_an_answer_held_off_longer_than_the_delivery_budget_still_resumes_the_process(): void {
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $id = (int) $p->get_id();
    $this->hold($id);
    $subscriber = $this->resume_subscriber(UserJoined::class);

    $first = $this->deliver(UserJoined::class, ['user_id' => 5]);

    self::assertContains($subscriber, $first->delivered, 'the resume subscriber succeeded: the fact is parked, not failed');
    self::assertSame(0, $this->ledger->attempts($subscriber, self::EVENT_ID), 'no delivery attempt was spent');
    [$retry] = $this->retries();
    self::assertSame($id, $retry->process_id);
    self::assertSame('suspended', $retry->expected_status);
    self::assertSame(0, $retry->step_index, 'the suspended step the fact answers');
    self::assertSame(UserJoined::class, $retry->fact['class'] ?? null);
    self::assertSame(['user_id' => 5], $retry->fact['payload'] ?? null);
    self::assertSame(self::EVENT_ID, $retry->fact['event_id'] ?? null);
    self::assertEquals($this->clock->now()->modify('+' . WakeRetryPolicy::backoff_seconds(1) . ' seconds'), $retry->due_at);

    // Redeliveries (the transport's own retries) find the subscriber delivered.
    for ($i = 0; $i < self::BUDGET + 1; $i++) {
      $again = $this->deliver(UserJoined::class, ['user_id' => 5]);
      self::assertContains($subscriber, $again->skipped);
    }

    // Held off past the delivery budget and past the wake budget: the wake is
    // retried on its own budget and kept, the process keeps waiting.
    for ($i = 0; $i < WakeRetryPolicy::BUDGET + 2; $i++) {
      $this->clock->advance(WakeRetryPolicy::CAP_SECONDS . ' seconds');
      $report = $this->drain();
      self::assertSame([$retry->key], $report->wakes_retried);
    }
    self::assertSame('suspended', $this->store->status_of($id));
    self::assertFalse($this->ledger->exhausted($subscriber, self::EVENT_ID), 'never dead-lettered');
    self::assertCount(1, $this->wakeups->items(null, 10), 'visible in the operator view (wakeup layer)');

    $this->release($id);
    $this->clock->advance(WakeRetryPolicy::CAP_SECONDS . ' seconds');
    $report = $this->drain();

    self::assertSame([$retry->key], $report->wakes_completed);
    self::assertSame(['invite', 'greet'], Journal::$steps);
    self::assertSame('completed', $this->store->status_of($id));
    self::assertSame([], $this->retries());
  }

  public function test_a_parked_answer_is_stale_safe_when_the_process_moved_on(): void {
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $id = (int) $p->get_id();
    $this->hold($id);
    $this->deliver(UserJoined::class, ['user_id' => 5]);
    [$retry] = $this->retries();

    // The lock frees and another matching fact resumes the process first.
    $this->release($id);
    $this->deliver(UserJoined::class, ['user_id' => 5], self::OTHER_EVENT_ID);
    self::assertSame('completed', $this->store->status_of($id));

    $this->clock->advance('10 seconds');
    $report = $this->drain();

    self::assertSame([$retry->key], $report->wakes_completed, 'a stale wake completes as a no-op');
    self::assertSame(['invite', 'greet'], Journal::$steps, 'greeted once');
  }

  public function test_a_parked_answer_counts_as_taken_for_first_wins_awaits(): void {
    $a = new AwaitingProcess(5);
    $b = new AwaitingProcess(5);
    $this->runner->start($a);
    $this->runner->start($b);
    $this->hold((int) $a->get_id());

    $this->deliver(UserJoined::class, ['user_id' => 5]);

    self::assertSame('suspended', $this->store->status_of((int) $b->get_id()), '0.6 first-wins: the first accepting process owns the fact');
    $this->release((int) $a->get_id());
    $this->clock->advance('10 seconds');
    $this->drain();

    self::assertSame('completed', $this->store->status_of((int) $a->get_id()));
    self::assertSame('suspended', $this->store->status_of((int) $b->get_id()));
  }

  public function test_parking_the_same_fact_twice_writes_one_wakeup(): void {
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $this->hold((int) $p->get_id());

    Correlation::within(TraceContext::root()->for_fact(self::EVENT_ID, UserJoined::class), function (): void {
      $this->runner->resume_on_event(new UserJoined(5));
      $report = $this->runner->resume_with_outcome(new UserJoined(5));
      self::assertFalse($report->is_unheard(), 'a parked fact was heard');
    });

    self::assertCount(1, $this->retries());
  }

  public function test_a_parked_wake_keeps_the_r1_reachability_guard(): void {
    // R1 on an exact-match (0.6 repository) store: a subclass fact never
    // reaches a 0.6-shaped parent-class await, on the wake path as on delivery.
    $repo = new ArrayProcessRepository();
    $store = new LegacyProcessStore($repo, new StaticConsumerIdentity('acme'), new InMemoryNamedLock(), new RecordingLogger());
    $runner = $this->runner($this->wakeups, $store);
    $runner->register_event(MemberJoined::class);
    $runner->register_event(VipJoined::class);
    $p = new ParentClassWaitProcess();
    $runner->start($p);
    $id = (int) $p->get_id();
    $step = $repo->find($id)->current_step_index();

    $fact = ResumeSource::fact(new VipJoined(4), self::EVENT_ID);
    $runner->wake(WakeupIntent::resume_fact('acme', $id, $step, $fact, $this->clock->now()));
    self::assertSame('suspended', $repo->find($id)->status(), 'the subclass fact stays unheard');

    $fact = ResumeSource::fact(new MemberJoined(4), self::OTHER_EVENT_ID);
    $runner->wake(WakeupIntent::resume_fact('acme', $id, $step, $fact, $this->clock->now()));
    self::assertSame('completed', $repo->find($id)->status());
  }

  public function test_a_scheduler_that_cannot_carry_facts_keeps_the_delivery_retry(): void {
    $plain = new class ($this->wakeups) implements IWakeupScheduler {
      public function __construct(private readonly InMemoryWakeupScheduler $inner) {}
      public function schedule(WakeupIntent $i): void { $this->inner->schedule($i); }
      public function cancel(string $idempotencyKey): void { $this->inner->cancel($idempotencyKey); }
      public function claim_due(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array { return $this->inner->claim_due($now, $limit, $leaseSeconds); }
      public function complete(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $w): bool { return $this->inner->complete($w); }
      public function retry_later(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool { return $this->inner->retry_later($w, $error, $nextAt); }
    };
    $this->registry = new SubscriptionRegistry();
    $this->runner = $this->runner($plain);
    $this->runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $this->hold((int) $p->get_id());

    $outcome = $this->deliver(UserJoined::class, ['user_id' => 5]);

    self::assertContains($this->resume_subscriber(UserJoined::class), $outcome->failed, 'the wave-3 behaviour: the delivery retries the fact');
    self::assertSame([], $this->retries());
  }

  public function test_a_resume_outside_a_fact_scope_without_an_event_id_still_throws(): void {
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    $this->hold((int) $p->get_id());

    $this->expectException(ProcessLockUnavailable::class);
    $this->runner->resume_on_event(new UserJoined(5));
  }

  // ── AW1 ───────────────────────────────────────────────────────────────────

  private function job_of(CauseReadingProcess $p): string {
    return (string) $this->store->find((int) $p->get_id())?->await_routes()[0]->await_key;
  }

  public function test_a_post_await_step_reads_the_event_id_of_the_resuming_fact(): void {
    $p = new CauseReadingProcess();
    $this->runner->start($p);

    $this->deliver(JobFinished::class, ['job_id' => $this->job_of($p), 'ok' => true]);

    self::assertSame(['order:NULL', 'answer:' . self::EVENT_ID, 'finish:NULL'], Journal::$steps, 'only the resumed step sees it');
    self::assertSame('completed', $this->store->status_of((int) $p->get_id()));
  }

  public function test_a_retried_post_await_step_reads_the_same_event_id_from_the_row(): void {
    CauseReadingProcess::$failures = 1;
    $p = new CauseReadingProcess();
    $this->runner->start($p);
    $this->deliver(JobFinished::class, ['job_id' => $this->job_of($p), 'ok' => true]);
    self::assertSame('scheduled', $this->store->status_of((int) $p->get_id()));

    // A restarted worker runs the retry: the id must come from the row.
    $this->runner = $this->runner($this->wakeups);
    $this->drain();

    self::assertSame(['order:NULL', 'answer:' . self::EVENT_ID, 'answer:' . self::EVENT_ID, 'finish:NULL'], Journal::$steps);
  }

  public function test_a_parked_answer_resumes_with_its_own_event_id(): void {
    $p = new CauseReadingProcess();
    $this->runner->start($p);
    $this->hold((int) $p->get_id());
    $this->deliver(JobFinished::class, ['job_id' => $this->job_of($p), 'ok' => true]);

    $this->release((int) $p->get_id());
    $this->clock->advance('10 seconds');
    $this->drain();

    self::assertSame(['order:NULL', 'answer:' . self::EVENT_ID, 'finish:NULL'], Journal::$steps);
  }

  public function test_the_resuming_event_id_has_no_setter_on_the_process_aggregate(): void {
    // Application subclasses must not overwrite the D13 cause: only the
    // runner writes it, through the persistence-only ProcessSteps.
    self::assertFalse(method_exists(LongProcess::class, 'mark_resumed_by'));

    $p = new CauseReadingProcess();
    $this->runner->start($p);
    $this->deliver(JobFinished::class, ['job_id' => $this->job_of($p), 'ok' => true]);
    self::assertSame('answer:' . self::EVENT_ID, Journal::$steps[1]);
  }

  public function test_a_resume_inside_a_fact_scope_takes_the_scopes_event_id(): void {
    $p = new CauseReadingProcess();
    $this->runner->start($p);
    $job = $this->job_of($p);

    Correlation::within(
      TraceContext::root()->for_fact(self::OTHER_EVENT_ID, JobFinished::class),
      fn () => $this->runner->resume(new JobFinished($job, true)),
    );

    self::assertSame('answer:' . self::OTHER_EVENT_ID, Journal::$steps[1]);
  }
}
