<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\Fixtures\Process\MakeWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;

/**
 * The process runner wiring (register X3, 5.2, scenario process.start-from-web;
 * W3C-R4): on sf the runner is built with StartMode::Deferred, so start()
 * persists the process plus a Continue intent and the first step runs in a
 * worker; ddd.process.inband_start: true maps to StartMode::InBand.
 */
final class ProcessFactoryTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;

  protected function setUp(): void {
    ProcessJournal::reset();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->lock = new InMemoryProcessLock();
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
  }

  protected function tearDown(): void {
    RuntimeReset::forget_for_tests();
    ProcessJournal::reset();
  }

  private function runner(bool $inbandStart, ?RecordingLogger $log = null): ProcessRunner {
    return Factory::process_runner(
      new SymfonyConsumerConfig('acme', 'App'),
      new ReentrantProcessLock($this->lock),
      $this->store,
      $this->wakeups,
      new SubscriptionRegistry(),
      $this->boundary,
      $this->clock,
      $inbandStart,
      $log,
    );
  }

  public function test_the_default_start_persists_the_process_and_a_continue_intent_and_runs_no_step(): void {
    $log = new RecordingLogger();
    $process = new MakeWidgetProcess('w-1');

    $this->runner(false, $log)->start($process);

    $id = (int) $process->get_id();
    self::assertSame('scheduled', $this->store->status_of($id), 'persisted, first step not run');
    $intents = $this->wakeups->pending();
    self::assertCount(1, $intents);
    self::assertSame(WakeKind::Continue, $intents[0]->kind);
    self::assertSame("continue:$id:0", $intents[0]->key);
    self::assertSame([], ProcessJournal::$steps, 'no step ran in the caller');
    self::assertSame(0, $this->lock->acquisitions(), 'no process lock was taken');
    self::assertSame('', $log->text(), 'nothing to warn about: core has the start mode');
  }

  public function test_the_in_band_opt_in_runs_the_first_step_at_start(): void {
    $process = new MakeWidgetProcess('w-1');

    $this->runner(true)->start($process);

    self::assertSame(['make', 'finish'], ProcessJournal::$steps, 'StartMode::InBand: the steps run in start()');
    self::assertSame('completed', $this->store->status_of((int) $process->get_id()));
    self::assertSame(1, $this->lock->acquisitions());
  }

  public function test_the_process_lock_is_the_reentrant_wrapper_over_the_advisory_lock(): void {
    $conn = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'x']);

    $lock = Factory::process_lock($conn, 'warn');

    self::assertInstanceOf(ReentrantProcessLock::class, $lock);
    self::assertInstanceOf(PostgresAdvisoryProcessLock::class, $lock->inner());
    self::assertSame(PoolerPolicy::Warn, PoolerPolicy::from('warn'));
  }
}
