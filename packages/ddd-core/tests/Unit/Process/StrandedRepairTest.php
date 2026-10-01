<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Repair\FailStrandedProcess;
use TangibleDDD\Application\Process\Repair\FailStrandedProcessHandler;
use TangibleDDD\Application\Process\Repair\ProcessNotStranded;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcess;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcessHandler;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\KeyedJobProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\ReadinessProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\RefundingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';
require_once dirname(__DIR__) . '/Fixtures/Process/Wave4Processes.php';

/**
 * WP8-10 / register 5.3 step 5 and 3.10: the ResumeStrandedProcess and
 * FailStrandedProcess repair commands, with their status, lease and
 * version guards, on the mem doubles.
 */
final class StrandedRepairTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private InMemoryProcessLock $lock;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingCommand::$onSend = null;
    KeyedJobProcess::$onRecord = null;
    ReadinessProcess::$ready = false;
    ReadinessProcess::$onProvision = null;
    ReadinessProcess::$statusProbe = null;

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->store->attachIntents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->lock = new InMemoryProcessLock();
    $this->runner = new ProcessRunner(
      new AcmeConfig(), null, $this->lock, $this->store, $this->wakeups, new SubscriptionRegistry(),
      $this->boundary, $this->clock, StartMode::Deferred,
    );
  }

  protected function tearDown(): void {
    Correlation::reset();
    HostDefaults::resetForTests();
  }

  /** A process a worker took (`running`) and then died in, 16 minutes ago: no live intent. */
  private function stranded(\TangibleDDD\Application\Process\LongProcess $p): int {
    $this->runner->start($p);
    $id = (int) $p->get_id();
    $copy = $this->store->find($id);
    $copy->advance(status: 'running', payload: $copy->payload());
    $this->store->save($copy, (int) $this->store->versionOf($id));
    $this->boundary->run(function () use ($id): void {
      foreach ($this->wakeups->pending() as $intent) {
        if ($intent->processId === $id) {
          $this->wakeups->cancel($intent->idempotencyKey);
        }
      }
    });
    $this->clock->advance('PT16M');
    return $id;
  }

  private function resumeHandler(): ResumeStrandedProcessHandler {
    return new ResumeStrandedProcessHandler($this->store, $this->wakeups, $this->lock, $this->clock, $this->boundary);
  }

  private function failHandler(): FailStrandedProcessHandler {
    return new FailStrandedProcessHandler($this->store, $this->wakeups, $this->lock, $this->clock, $this->boundary);
  }

  private function drainDue(): void {
    foreach ($this->wakeups->claimDue($this->clock->now(), 50, 60) as $claimed) {
      $this->runner->wake($claimed->intent);
      $this->boundary->run(fn () => $this->wakeups->complete($claimed));
    }
  }

  public function test_the_repairs_are_transactional_commands(): void {
    self::assertInstanceOf(ITransactionalCommand::class, new ResumeStrandedProcess('acme', 1));
    self::assertInstanceOf(ITransactionalCommand::class, new FailStrandedProcess('acme', 1, 'why'));
  }

  public function test_resume_re_runs_the_stranded_step_with_the_same_command_ids(): void {
    $id = $this->stranded(new TwoStepProcess(4));
    $version = (int) $this->store->versionOf($id);

    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id, $version));

    [$intent] = $this->wakeups->pending();
    self::assertSame(WakeKind::ResumeRetry, $intent->kind);
    self::assertSame('running', $intent->expectedStatus);
    self::assertSame($version + 1, $intent->retryVersion(), 'the repair fences the row and the wake expects the fenced version');
    self::assertSame(0, $this->lock->heldCount(), 'the lease guard was released');

    $this->drainDue();

    self::assertSame(['reserve', 'ship'], Journal::$steps);
    self::assertSame('completed', $this->store->statusOf($id));
    self::assertSame(DeterministicCommandId::forStep('acme', $id, '0', 0), RecordingCommand::$hints[0], 'the re-run step dispatches its deterministic id');
  }

  public function test_resume_re_runs_a_stranded_post_await_step_with_the_fact_it_was_resumed_with(): void {
    // D3 crash-after-fact: the resuming save committed (`running` at `record`),
    // then the worker died inside `record`. The repair must re-run `record`
    // with the same JobFinished, not with null.
    $this->runner->register_event(JobFinished::class);
    $p = new KeyedJobProcess();
    $this->runner->start($p);
    $this->drainDue();
    $id = (int) $p->get_id();
    self::assertSame('suspended', $this->store->statusOf($id));
    $job = RecordingCommand::$sent[0]->data;

    KeyedJobProcess::$onRecord = static function (): void {
      // A death the runner cannot catch as a business failure: the row stays as the resume saved it.
      throw new \TangibleDDD\Runtime\Process\ConcurrentProcessModification('worker died');
    };
    try {
      $this->runner->resume_with_outcome(new JobFinished($job, true));
      self::fail('expected the simulated death');
    } catch (\TangibleDDD\Runtime\Process\ConcurrentProcessModification) {
    } finally {
      KeyedJobProcess::$onRecord = null;
    }
    self::assertSame('running', $this->store->statusOf($id));
    self::assertSame(1, $this->store->find($id)->current_step_index());

    $seen = null;
    KeyedJobProcess::$onRecord = static function (JobFinished $done) use (&$seen): void {
      $seen = $done;
    };
    $this->clock->advance('PT16M');
    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id, (int) $this->store->versionOf($id)));
    $this->drainDue();
    KeyedJobProcess::$onRecord = null;

    self::assertSame('completed', $this->store->statusOf($id));
    self::assertSame(['order:' . $job, 'record:ok', 'finish'], Journal::$steps);
    self::assertInstanceOf(JobFinished::class, $seen);
    self::assertSame($job, $seen->job_id, 'the re-run receives the same fact');
  }

  public function test_resume_re_runs_a_stranded_step_resumed_by_the_precheck_with_its_argument(): void {
    $this->runner->register_event(JobFinished::class);
    ReadinessProcess::$ready = true;
    ReadinessProcess::$onProvision = static function (): void {
      throw new \TangibleDDD\Runtime\Process\ConcurrentProcessModification('worker died');
    };
    $p = new ReadinessProcess();
    $this->runner->start($p);
    try {
      $this->drainDue();
      self::fail('expected the simulated death');
    } catch (\TangibleDDD\Runtime\Process\ConcurrentProcessModification) {
    } finally {
      ReadinessProcess::$onProvision = null;
    }
    $id = (int) $p->get_id();
    self::assertSame('running', $this->store->statusOf($id));

    $this->clock->advance('PT16M');
    // The dead worker's Continue claim lapses; its redelivery is stale (the row is `running`) and completes.
    $this->drainDue();
    self::assertSame('running', $this->store->statusOf($id));
    self::assertSame([], $this->wakeups->pending());

    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id, (int) $this->store->versionOf($id)));
    $this->drainDue();

    self::assertSame('completed', $this->store->statusOf($id));
    self::assertSame(['await_ready', 'provision:string'], Journal::$steps, 'the re-run receives the precheck argument');
  }

  public function test_inside_the_command_transaction_a_worker_acting_on_the_pre_repair_row_is_fenced_off(): void {
    // TransactionalCommandMiddleware: the repair joins the open transaction,
    // and the process lock is released before that transaction commits.
    $id = $this->stranded(new TwoStepProcess(4));
    $before = (int) $this->store->versionOf($id);
    $stale = $this->store->find($id);

    $this->expectException(\TangibleDDD\Runtime\Process\ConcurrentProcessModification::class);
    $this->boundary->run(function () use ($id, $before, $stale): void {
      $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id, $before));
      self::assertSame(0, $this->lock->heldCount());
      // A worker takes the free lock and saves from the row it read before the repair.
      $this->store->save($stale, $before);
    });
  }

  public function test_resume_refuses_a_process_that_is_not_stranded(): void {
    $this->runner->start(new TwoStepProcess());
    // `scheduled` with a live Continue intent and a fresh updated_at.

    $this->expectException(ProcessNotStranded::class);
    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', 1));
  }

  public function test_resume_refuses_while_a_worker_holds_the_process_lock(): void {
    $id = $this->stranded(new TwoStepProcess());
    $this->lock->holdElsewhere(new LockKey('acme', '', $id));

    try {
      $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id));
      self::fail('expected the lease guard');
    } catch (ProcessNotStranded $e) {
      self::assertStringContainsString('lock', $e->getMessage());
    }
    self::assertSame([], $this->wakeups->pending());
  }

  public function test_resume_refuses_a_stale_expected_version(): void {
    $id = $this->stranded(new TwoStepProcess());

    $this->expectException(ProcessNotStranded::class);
    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id, 1));
  }

  public function test_resume_of_an_unknown_process_is_refused(): void {
    $this->expectException(ProcessNotStranded::class);
    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', 99));
  }

  public function test_fail_marks_the_process_failed_without_running_anything(): void {
    $id = $this->stranded(new TwoStepProcess());

    $this->failHandler()->handle(new FailStrandedProcess('acme', $id, 'courier API gone'));

    self::assertSame('failed', $this->store->statusOf($id));
    self::assertStringContainsString('courier API gone', (string) $this->store->find($id)->last_error());
    self::assertSame([], $this->wakeups->pending());
    self::assertSame([], Journal::$steps);
    self::assertSame(0, $this->lock->heldCount());
  }

  public function test_fail_with_compensation_runs_the_compensations_in_a_worker(): void {
    $p = new RefundingProcess();
    $id = $this->stranded($p);
    // Move the stranded row to step 1 (charge done), as a crash in `deliver` would leave it.
    $copy = $this->store->find($id);
    $copy->advance_step();
    $this->store->save($copy, (int) $this->store->versionOf($id));
    $this->clock->advance('PT16M');

    $this->failHandler()->handle(new FailStrandedProcess('acme', $id, 'operator gave up', compensate: true));
    self::assertSame('scheduled', $this->store->statusOf($id), 'compensation runs in the drain, not in the command');
    self::assertSame([], Journal::$steps);

    $this->drainDue();

    self::assertSame(['refund'], Journal::$steps);
    self::assertSame('failed', $this->store->statusOf($id));
  }

  public function test_fail_refuses_a_completed_process(): void {
    $id = $this->stranded(new TwoStepProcess());
    $this->resumeHandler()->handle(new ResumeStrandedProcess('acme', $id));
    $this->drainDue();

    $this->expectException(ProcessNotStranded::class);
    $this->failHandler()->handle(new FailStrandedProcess('acme', $id, 'too late'));
  }

  public function test_the_host_supplies_the_ports_for_the_command_prefix(): void {
    $id = $this->stranded(new TwoStepProcess());
    $ports = [IProcessStore::class => $this->store, IWakeupScheduler::class => $this->wakeups];
    HostDefaults::provide(IHostPortFactory::class, new class($ports) implements IHostPortFactory {
      public function __construct(private array $ports) {}
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        return $consumer->prefix() === 'acme' ? ($this->ports[$port] ?? null) : null;
      }
    });
    HostDefaults::provide(IProcessLock::class, $this->lock);
    HostDefaults::provide(IClock::class, $this->clock);
    HostDefaults::provide(ITransactionBoundary::class, $this->boundary);

    (new ResumeStrandedProcessHandler())->handle(new ResumeStrandedProcess('acme', $id));

    self::assertCount(1, $this->wakeups->pending());
  }

  public function test_without_a_store_the_handler_fails_loudly(): void {
    $this->expectException(\LogicException::class);
    (new FailStrandedProcessHandler())->handle(new FailStrandedProcess('acme', 1, 'x'));
  }
}
