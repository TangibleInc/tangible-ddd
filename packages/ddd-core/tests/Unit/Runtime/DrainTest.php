<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TimedGatherProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeHandler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * Register 3.6: Drain::run_once(maxItems, maxSeconds) is one bounded pass:
 * the relay step, due deliveries, due wakeups, then the stranded scan. It
 * never loops or sleeps, resets the runtime after each item and re-queues
 * a failed wake with the wake backoff.
 */
final class DrainTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryOutboxStore $outbox;
  private InMemoryTransport $transport;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private RecordingLogger $logger;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    $this->logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $this->logger);
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingCommand::$onSend = null;

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->outbox = new InMemoryOutboxStore($this->clock, null, $this->boundary);
    $this->boundary->enlist($this->outbox);
    $this->transport = new InMemoryTransport();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->store->attach_intents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->lock = new InMemoryProcessLock();
    $this->runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, new SubscriptionRegistry(),
      $this->boundary, $this->clock, StartMode::Deferred,
    );
  }

  protected function tearDown(): void {
    RuntimeReset::forget_for_tests();
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  private function drain(?IDeliveryWorker $delivery = null, ?OutboxProcessor $relay = null): Drain {
    return new Drain(
      relay: $relay ?? new OutboxProcessor(new AcmeConfig(), null, new OutboxConfig(), null, null, $this->logger, $this->clock, $this->outbox, $this->transport, $this->boundary),
      wakeups: $this->wakeups,
      processWakes: $this->runner,
      delivery: $delivery,
      stranded: $this->runner,
      clock: $this->clock,
      logger: $this->logger,
    );
  }

  private function append(string $id): void {
    $this->outbox->append(new OutboxRecord($id, 'order_placed', 'acme_integration_order_placed', 'c', 1, null, ['n' => 1], $this->clock->now()));
  }

  public function test_one_pass_relays_delivers_and_runs_due_wakes(): void {
    $this->append('e1');
    $this->runner->start(new TwoStepProcess(1));   // deferred: a due Continue intent
    $delivery = new class implements IDeliveryWorker {
      public array $calls = [];
      public function run_due(\DateTimeImmutable $now, int $limit): int { $this->calls[] = $limit; return 1; }
    };

    $report = $this->drain($delivery)->run_once(50, 50);

    self::assertSame(['e1'], $report->relay?->accepted);
    self::assertSame(1, $report->delivered);
    self::assertSame([49], $delivery->calls, 'the delivery stage gets what is left of the item budget');
    self::assertSame(['continue:1:0'], $report->wakes_completed);
    self::assertSame(['reserve', 'ship'], Journal::$steps);
    self::assertSame(3, $report->items);
    self::assertSame('idle', $report->stopped_by);
    self::assertSame([], $this->wakeups->pending(), 'the completed intent is gone');
  }

  public function test_the_item_budget_bounds_the_pass(): void {
    foreach (['a', 'b', 'c'] as $id) {
      $this->append($id);
    }
    $this->runner->start(new TwoStepProcess(1));

    $report = $this->drain()->run_once(2, 50);

    self::assertSame(2, $report->relay?->total);
    self::assertSame([], $report->wakes_completed, 'no budget left for wakes');
    self::assertSame('max_items', $report->stopped_by);
    self::assertCount(1, $this->wakeups->pending());
  }

  public function test_a_budget_used_up_by_the_last_stage_reports_max_items(): void {
    $this->append('a');
    $this->append('b');
    $relayOnly = new Drain(
      relay: new OutboxProcessor(new AcmeConfig(), null, new OutboxConfig(), null, null, $this->logger, $this->clock, $this->outbox, $this->transport, $this->boundary),
      clock: $this->clock,
    );

    self::assertSame('max_items', $relayOnly->run_once(2)->stopped_by);
    self::assertSame('idle', $relayOnly->run_once(2)->stopped_by);
  }

  public function test_the_time_budget_stops_before_any_work(): void {
    $this->append('a');

    $report = $this->drain()->run_once(200, 0);

    self::assertNull($report->relay);
    self::assertSame('max_seconds', $report->stopped_by);
    self::assertSame('pending', $this->outbox->status_of('a'));
  }

  public function test_a_contended_wake_is_re_queued_with_the_wake_backoff_and_later_succeeds(): void {
    // lock.contention / process.intent-survives-queue-failure on mem
    $this->runner->start(new TwoStepProcess(1));
    $this->lock->hold_elsewhere(new LockKey('acme', '', 1));

    $first = $this->drain()->run_once();
    self::assertSame(['continue:1:0'], $first->wakes_retried);
    self::assertSame([], Journal::$steps);
    self::assertSame([], $this->drain()->run_once()->wakes_retried, 'not due again before the backoff');

    $this->lock->release_elsewhere(new LockKey('acme', '', 1));
    $this->clock->advance('+' . WakeRetryPolicy::backoff_seconds(1) . ' seconds');
    $second = $this->drain()->run_once();

    self::assertSame(['continue:1:0'], $second->wakes_completed);
    self::assertSame(['reserve', 'ship'], Journal::$steps);
    self::assertSame([], $this->wakeups->pending(), 'one intent, retried once: no ResumeRetry duplicate');
  }

  public function test_a_wake_at_its_budget_is_reported_exhausted_and_still_kept(): void {
    $this->runner->start(new TwoStepProcess(1));
    $this->lock->hold_elsewhere(new LockKey('acme', '', 1));

    $exhausted = [];
    for ($i = 1; $i <= WakeRetryPolicy::BUDGET; $i++) {
      $exhausted = $this->drain()->run_once()->wakes_exhausted;
      $this->clock->advance('+' . WakeRetryPolicy::CAP_SECONDS . ' seconds');
    }

    self::assertSame(['continue:1:0'], $exhausted);
    self::assertCount(1, $this->wakeups->pending(), 'never dropped');
  }

  public function test_a_due_timeout_runs_through_the_runner(): void {
    $runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, new SubscriptionRegistry(),
      $this->boundary, $this->clock,
    );
    $runner->register_event(UserJoined::class);
    $runner->start(new TimedGatherProcess());
    $this->clock->advance('+61 seconds');

    $report = (new Drain(wakeups: $this->wakeups, processWakes: $runner, clock: $this->clock, logger: $this->logger))->run_once();

    self::assertSame(['timeout:1:1'], $report->wakes_completed);
    self::assertSame(['prepare', 'gather', 'undo_prepare'], Journal::$steps);
  }

  public function test_deliver_wakes_go_to_their_own_handler(): void {
    $this->boundary->run(fn () => $this->wakeups->schedule(
      new WakeupIntent(WakeKind::Deliver, 'acme', null, null, null, $this->clock->now(), 'deliver:e1:sub')
    ));
    $handler = new class implements IWakeHandler {
      public array $seen = [];
      public function wake(WakeupIntent $intent): void { $this->seen[] = $intent->key; }
    };

    $report = (new Drain(wakeups: $this->wakeups, processWakes: $this->runner, clock: $this->clock, logger: $this->logger, deliverWakes: $handler))->run_once();

    self::assertSame(['deliver:e1:sub'], $handler->seen);
    self::assertSame(['deliver:e1:sub'], $report->wakes_completed);
  }

  public function test_the_stranded_scan_runs_once_per_pass(): void {
    $this->runner->start(new TwoStepProcess(1));
    foreach ($this->wakeups->claim_due($this->clock->now(), 10, 60) as $w) {
      $this->wakeups->complete($w);
    }
    $this->clock->advance('+16 minutes');

    $report = $this->drain()->run_once();

    self::assertSame([1], $report->stranded?->requeued);
    self::assertSame([], Journal::$steps, 'the re-queued continuation runs in the next pass');
    $this->drain()->run_once();
    self::assertSame(['reserve', 'ship'], Journal::$steps);
  }

  public function test_the_runtime_is_reset_after_each_item_and_a_leak_is_reported(): void {
    $this->runner->start(new TwoStepProcess(1));
    $resets = 0;
    RuntimeReset::register('test.counter', static function () use (&$resets): void {
      $resets++;
    });
    $leakyLock = new InMemoryProcessLock();
    RuntimeReset::guard($leakyLock);
    RecordingCommand::$onSend = static function () use ($leakyLock): void {
      // A bracket bug: a lock left held by the wake.
      if ($leakyLock->held_count() === 0) {
        $leakyLock->acquire(new LockKey('acme', '', 99), 0.0);
      }
    };

    $report = $this->drain()->run_once();

    self::assertGreaterThanOrEqual(2, $resets, 'after the relay batch and after the wake');
    self::assertNotEmpty($report->leaks);
    self::assertStringContainsString('still held', $report->leaks[0]);
    self::assertSame(0, $leakyLock->held_count(), 'force-released: the next item starts clean');
  }

  public function test_a_failing_stage_is_logged_and_the_pass_continues(): void {
    $this->runner->start(new TwoStepProcess(1));
    $delivery = new class implements IDeliveryWorker {
      public function run_due(\DateTimeImmutable $now, int $limit): int { throw new \RuntimeException('jobs table gone'); }
    };

    $report = $this->drain($delivery)->run_once();

    self::assertCount(1, $report->errors);
    self::assertStringContainsString('jobs table gone', $report->errors[0]);
    self::assertSame(['continue:1:0'], $report->wakes_completed);
  }
}
