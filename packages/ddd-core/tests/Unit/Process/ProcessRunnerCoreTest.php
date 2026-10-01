<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitedEventNotRegistered;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\AwaitingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\IgnitedProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\RefundingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TimedGatherProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IProcessEntry;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * Register section 8 wave 2 / ruling #70 option (b): the core ProcessRunner
 * runs behind the wave-1 ports, here the in-memory doubles, with no
 * WordPress stub loaded (this suite's bootstrap refuses one).
 */
final class ProcessRunnerCoreTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private SubscriptionRegistry $registry;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];

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
      new AcmeConfig(),
      null,
      $this->lock,
      $this->store,
      $this->wakeups,
      $this->registry,
      $this->boundary,
      $this->clock,
    );
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function deliver(string $class, array $payload, ?string $eventId = self::EVENT_ID, ?InMemoryDeliveryLedger $ledger = null): void {
    (new IntegrationDelivery($this->registry, $ledger ?? new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger()))
      ->deliver($class, IntegrationEnvelope::wrap($payload, 'corr-1', 1, $eventId));
  }

  public function test_the_runner_is_the_process_entry_port(): void {
    self::assertInstanceOf(IProcessEntry::class, $this->runner);
  }

  public function test_the_0_6_5_two_argument_constructor_stays_callable(): void {
    $runner = new ProcessRunner(new AcmeConfig(), $this->createStub(\TangibleDDD\Infra\IProcessRepository::class));
    self::assertInstanceOf(ProcessRunner::class, $runner);
  }

  public function test_start_runs_every_step_in_a_trajectory_scope_and_completes(): void {
    $p = new TwoStepProcess(7);
    $this->runner->start($p);

    self::assertSame(['reserve', 'ship'], Journal::$steps);
    self::assertSame(['Trajectory', 'Trajectory'], Journal::$causes);
    self::assertSame(['reserve', 'ship'], RecordingCommand::labels());
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
    self::assertGreaterThan(1, $this->store->versionOf($p->get_id()), 'each state change is a versioned save');
    self::assertSame(0, $this->lock->heldCount(), 'the wake lock is released');
    self::assertNull(Correlation::peek(), 'the wake scope is closed');
  }

  public function test_a_suspended_process_resumes_on_the_awaited_fact(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $this->runner->start($p);
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));

    $this->deliver(UserJoined::class, ['user_id' => 6]);
    self::assertSame(['invite'], Journal::$steps, 'a non-matching fact does not wake it');

    $this->deliver(UserJoined::class, ['user_id' => 5]);
    self::assertSame(['invite', 'greet'], Journal::$steps);
    self::assertSame(['invite', 'greet'], RecordingCommand::labels());
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_register_event_subscribes_at_resume_priority(): void {
    $this->runner->register_event(UserJoined::class);
    $subs = $this->registry->for(UserJoined::class);

    self::assertCount(1, $subs);
    self::assertSame(Subscriber::RESUME, $subs[0]->priority);
  }

  public function test_suspending_on_an_unregistered_fact_is_a_wiring_error(): void {
    $this->expectException(AwaitedEventNotRegistered::class);
    $this->runner->start(new AwaitingProcess(5));
  }

  public function test_resume_subscriptions_made_by_the_registrar_count_as_registered(): void {
    $this->registry->add(new Subscriber('resume:' . UserJoined::class, Subscriber::RESUME, UserJoined::class, static fn () => null));

    $p = new AwaitingProcess(5);
    $this->runner->start($p);

    self::assertSame('suspended', $this->store->statusOf($p->get_id()));
  }

  public function test_suspend_with_a_timeout_writes_the_intent_in_the_process_transaction(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();
    $this->runner->start($p);

    $intents = $this->wakeups->pending();
    self::assertCount(1, $intents);
    self::assertSame(WakeKind::Timeout, $intents[0]->kind);
    self::assertSame("timeout:{$p->get_id()}:1", $intents[0]->idempotencyKey);
    self::assertSame(1, $intents[0]->stepIndex);
    self::assertSame('suspended', $intents[0]->expectedStatus);
    self::assertEquals($this->clock->now()->modify('+60 seconds'), $intents[0]->dueAt, 'absolute UTC due time from the clock');
    self::assertSame('acme', $intents[0]->consumer);
  }

  public function test_a_failed_intent_write_rolls_back_the_suspension(): void {
    // 5.3: the intent and the state change commit together or not at all.
    $inner = $this->wakeups;
    $failing = new class($inner) implements \TangibleDDD\Runtime\Scheduling\IWakeupScheduler {
      public function __construct(private InMemoryWakeupScheduler $inner) {}
      public function schedule(\TangibleDDD\Runtime\Scheduling\WakeupIntent $i): void {
        $this->inner->schedule($i);           // written, then the store fails
        throw new \RuntimeException('intent table unavailable');
      }
      public function cancel(string $k): void { $this->inner->cancel($k); }
      public function claimDue(\DateTimeImmutable $n, int $l, int $s): array { return $this->inner->claimDue($n, $l, $s); }
      public function complete(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $w): bool { return $this->inner->complete($w); }
      public function retryLater(\TangibleDDD\Runtime\Scheduling\ClaimedWakeup $w, string $e, \DateTimeImmutable $n): bool { return $this->inner->retryLater($w, $e, $n); }
    };
    $runner = new ProcessRunner(new AcmeConfig(), null, $this->lock, $this->store, $failing, $this->registry, $this->boundary, $this->clock);
    $runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();

    try {
      $runner->start($p);
    } catch (\RuntimeException) {
    }

    self::assertSame([], $this->wakeups->pending(), 'the half-written intent rolled back with the save');
    self::assertNotSame('suspended', $this->store->statusOf((int) $p->get_id()), 'no suspension without its alarm');
  }

  public function test_the_timeout_fails_and_compensates_under_the_lock(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess(AwaitAll::TIMEOUT_FAIL);
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), 1);

    self::assertSame(['prepare', 'gather', 'undo_prepare'], Journal::$steps);
    self::assertSame(['undo'], RecordingCommand::labels());
    self::assertSame(1 + 1, $this->lock->acquireCount(), 'start + one balanced acquisition for the timeout (re-entrant)');
    self::assertSame(0, $this->lock->heldCount());
  }

  public function test_the_proceed_policy_resumes_with_the_partial_gather(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess(AwaitAll::TIMEOUT_PROCEED);
    $this->runner->start($p);
    $this->deliver(UserJoined::class, ['user_id' => 1]);

    $this->runner->handle_timeout($p->get_id(), 1);

    self::assertSame(['prepare', 'gather', 'report'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_a_stale_timeout_is_a_noop(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), 0);
    $this->runner->handle_timeout(999, 1);

    self::assertSame(['prepare', 'gather'], Journal::$steps);
    self::assertSame('suspended', $this->store->statusOf($p->get_id()));
  }

  public function test_a_failing_step_compensates_in_reverse(): void {
    $p = new RefundingProcess();
    $this->runner->start($p);

    self::assertSame(['charge', 'deliver', 'refund'], Journal::$steps);
    self::assertSame(['charge', 'refund'], RecordingCommand::labels());
    self::assertNotSame('running', $this->store->statusOf($p->get_id()));
  }

  public function test_a_lock_held_elsewhere_runs_nothing_and_throws_a_locking_exception(): void {
    $p = new TwoStepProcess(1);
    $this->lock->holdElsewhere(new LockKey('acme', '', 1));

    try {
      $this->runner->start($p);
      self::fail('expected a locking failure');
    } catch (LockingException $e) {
      self::assertInstanceOf(LockNotAcquired::class, $e->getPrevious(), 'the port error is kept as the cause');
    }

    self::assertSame([], Journal::$steps, 'no step runs unlocked');
    self::assertSame(0, $this->lock->heldCount());
  }

  public function test_a_backend_lock_error_on_a_wake_saves_nothing(): void {
    $this->runner->register_event(UserJoined::class);
    $p = new TimedGatherProcess();
    $this->runner->start($p);
    $version = $this->store->versionOf($p->get_id());
    $this->lock->failNextAcquire('connection lost');

    try {
      $this->runner->handle_timeout($p->get_id(), 1);
      self::fail('expected a locking failure');
    } catch (LockingException) {
    }

    self::assertSame($version, $this->store->versionOf($p->get_id()));
    self::assertSame(['prepare', 'gather'], Journal::$steps);
  }

  public function test_continuation_is_scheduled_as_a_durable_intent_and_resumes(): void {
    $this->forceContinuationAfterEachStep();
    $p = new TwoStepProcess(3);
    $this->runner->start($p);

    self::assertSame(['reserve'], Journal::$steps);
    self::assertSame('scheduled', $this->store->statusOf($p->get_id()));
    $intents = $this->wakeups->pending();
    self::assertSame(WakeKind::Continue, $intents[0]->kind);
    self::assertSame("continue:{$p->get_id()}:1", $intents[0]->idempotencyKey);

    $this->runner->continue_scheduled($p->get_id());
    self::assertSame(['reserve', 'ship'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($p->get_id()));
  }

  public function test_a_row_changed_before_the_lock_is_re_read_under_it(): void {
    // Wave 3 (C6, C7): another holder bumps the row between this runner's
    // unlocked pre-read and its lock. The wake re-reads under the lock, so
    // it works on the newer version instead of aborting on a stale one. (A
    // change AFTER the re-read is caught by the fenced touch before the
    // step's commands dispatch: ProcessRunnerWave3Test.)
    $store = $this->store;
    $interloper = null;
    $lock = new class($this->lock) implements \TangibleDDD\Runtime\Lock\IProcessLock {
      public ?\Closure $beforeAcquire = null;
      public function __construct(private \TangibleDDD\Runtime\Lock\IProcessLock $inner) {}
      public function acquire(LockKey $k, float $t): \TangibleDDD\Runtime\Lock\LockHandle {
        if ($this->beforeAcquire !== null) {
          ($this->beforeAcquire)($k);
          $this->beforeAcquire = null;
        }
        return $this->inner->acquire($k, $t);
      }
      public function release(\TangibleDDD\Runtime\Lock\LockHandle $h): void { $this->inner->release($h); }
      public function heldCount(): int { return $this->inner->heldCount(); }
      public function forceReleaseAll(): int { return $this->inner->forceReleaseAll(); }
    };
    $runner = new ProcessRunner(new AcmeConfig(), null, $lock, $store, $this->wakeups, $this->registry, $this->boundary, $this->clock);
    $runner->register_event(UserJoined::class);
    $p = new AwaitingProcess(5);
    $runner->start($p);
    $id = $p->get_id();

    $lock->beforeAcquire = static function () use ($store, $id): void {
      $store->touch($id, (int) $store->versionOf($id));
    };

    $runner->resume(new UserJoined(5));

    self::assertSame(['invite', 'greet'], Journal::$steps);
    self::assertSame('completed', $store->statusOf($id));
    self::assertSame(0, $this->lock->heldCount());
  }

  public function test_starts_on_ignites_exactly_once_per_fact(): void {
    (new SubscriptionRegistrar($this->registry, $this->runner))->registerProcess(IgnitedProcess::class);

    $this->deliver(OrderPlaced::class, ['order_id' => 4, 'sku' => 's']);
    $this->deliver(OrderPlaced::class, ['order_id' => 4, 'sku' => 's'], self::EVENT_ID, new InMemoryDeliveryLedger());

    self::assertSame(1, $this->store->count(), 'a redelivery of the same fact does not ignite twice');
    self::assertSame(['open:4'], Journal::$steps);
    self::assertSame(IgnitionKey::for(self::EVENT_ID, IgnitedProcess::class), $this->store->ignitionKeyOf(1));
  }

  public function test_register_start_ignites_through_the_registry(): void {
    $this->runner->register_start(IgnitedProcess::class, OrderPlaced::class);

    $this->deliver(OrderPlaced::class, ['order_id' => 9, 'sku' => 's']);

    self::assertSame(['open:9'], Journal::$steps);
    self::assertSame(Subscriber::IGNITION, $this->registry->for(OrderPlaced::class)[0]->priority);
  }

  public function test_a_declining_from_event_starts_nothing(): void {
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(0), self::EVENT_ID);
    self::assertSame(0, $this->store->count());
  }

  public function test_an_id_less_ignition_starts_without_dedup(): void {
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(2), '');
    $this->runner->ignite(IgnitedProcess::class, new OrderPlaced(2), '');

    self::assertSame(2, $this->store->count());
    self::assertNull($this->store->ignitionKeyOf(1));
  }

  public function test_a_manual_start_inside_a_fact_drain_is_never_deduped(): void {
    Correlation::within((new \TangibleDDD\Application\Correlation\TraceContext('c'))->for_fact(self::EVENT_ID), function (): void {
      $this->runner->start(new TwoStepProcess(1));
      $this->runner->start(new TwoStepProcess(2));
    });

    self::assertSame(2, $this->store->count());
    self::assertNull($this->store->ignitionKeyOf(1));
  }

  public function test_a_failed_process_emits_its_signal_through_the_host_dispatcher(): void {
    $signals = new class implements \TangibleDDD\Runtime\IInfrastructureSignalDispatcher {
      public array $seen = [];
      public function emit(\TangibleDDD\Application\Infrastructure\IInfrastructureEvent $e, \TangibleDDD\Infra\IConsumerIdentity $c): void {
        $this->seen[] = $c->prefix() . '_' . $e::action();
      }
    };
    HostDefaults::provide(\TangibleDDD\Runtime\IInfrastructureSignalDispatcher::class, $signals);

    try {
      $this->runner->start(new \TangibleDDD\Core\Tests\Unit\Fixtures\Process\BrokenCompensationProcess());
      self::fail('a failed compensation propagates');
    } catch (\LogicException $e) {
      self::assertSame('compensation broke', $e->getMessage());
    }

    self::assertContains('acme_process_failed', $signals->seen);
  }

  public function test_without_ports_the_runner_fails_loudly_on_first_use(): void {
    $bare = new ProcessRunner(new AcmeConfig());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('IProcessStore');
    $bare->start(new TwoStepProcess());
  }

  private function forceContinuationAfterEachStep(): void {
    (fn () => $this->max_execution_seconds = 0)->call($this->runner);
  }
}
