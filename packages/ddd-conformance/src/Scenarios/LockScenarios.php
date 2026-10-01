<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\ProcessLockUnavailable;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Scheduling\WakeKind;

/**
 * The per-process lock (register 3.7, 5.2; bug 1 extraction variant):
 * only a definite acquisition enters, a contended wake is re-queued and
 * later succeeds, and nesting is balanced with one backend acquisition per
 * wake. `lock.namespace` (two consumers, two connections) is in
 * ConcurrencyScenarios.
 */
abstract class LockScenarios extends ProcessScenarioCase {

  #[Group('lock.contention')]
  #[TestDox('lock.contention: another connection holds the process lock; the wake fails with LockNotAcquired, the row is unchanged, the wake is re-queued and later succeeds')]
  public function test_lock_contention(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $before = $this->row($id);
    $processes->hold_lock_elsewhere($id);
    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);

    // A direct wake entry (the in-band / Action Scheduler timeout callback).
    $thrown = self::thrown(static fn () => $processes->worker()->runner()->handle_timeout($id, 1));

    self::assertInstanceOf(ProcessLockUnavailable::class, $thrown);
    self::assertInstanceOf(LockNotAcquired::class, $thrown->getPrevious(), 'the port\'s LockNotAcquired is kept');
    $retries = $this->intents($id, WakeKind::ResumeRetry);
    self::assertCount(1, $retries, 're-queued as a ResumeRetry');
    self::assertSame('suspended', $retries[0]->expected_status);
    self::assertSame(1, $retries[0]->step_index);

    // A drained wake of the due timeout intent cannot lock either.
    $report = $processes->worker()->drain_once();
    self::assertContains("timeout:$id:1", $report->wakes_retried, 'the claimed intent is retried with the wake backoff');

    $after = $this->row($id);
    self::assertSame('suspended', $after->status, 'row unchanged');
    self::assertSame($before->version, $after->version, 'nothing was saved without the lock');
    self::assertSame(0, ProcessJournal::runs('undo_prepare'), 'the critical section never ran');

    // Later succeeds (extraction branch, section 6).
    $processes->release_lock_elsewhere($id);
    $this->host->advance_clock(self::PAST_WAKE_BACKOFF);
    $processes->worker()->drain_once();

    self::assertSame(1, ProcessJournal::runs('undo_prepare'), 'the timeout applied exactly once');
    self::assertSame('failed', $this->row($id)->status);
    self::assertSame([], $this->intents($id), 'the timeout and its retry are both done');
    self::assertSame(0, $processes->worker()->lock()->held_count());
  }

  #[Group('lock.acquire-error')]
  #[TestDox('lock.acquire-error: the lock backend answers NULL / false / an error; LockNotAcquired, the critical section never runs, nothing is saved')]
  public function test_lock_acquire_error(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $version = $this->row($id)->version;
    $steps = ProcessJournal::$steps;
    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);

    // The timeout path.
    $processes->fail_next_lock('GET_LOCK returned NULL');
    $thrown = self::thrown(static fn () => $processes->worker()->runner()->handle_timeout($id, 1));

    self::assertInstanceOf(ProcessLockUnavailable::class, $thrown);
    self::assertInstanceOf(LockNotAcquired::class, $thrown->getPrevious());
    self::assertSame($steps, ProcessJournal::$steps, 'critical section never entered');
    self::assertSame($version, $this->row($id)->version, 'no save');
    self::assertSame('suspended', $this->row($id)->status);

    // The resume path.
    $processes->fail_next_lock('pg_try_advisory_lock raised an error');
    $wrapped = self::wrap(new PartArrived('w-1', 'a'), Uuid::v4());
    $outcome = $this->host->deliver(PartArrived::class, $wrapped);

    self::assertTrue($outcome->needs_retry(), 'the resume subscriber failed; its delivery is retried');
    self::assertSame($version, $this->row($id)->version, 'no save');
    self::assertSame(0, $processes->worker()->lock()->held_count(), 'no release was owed');

    // The next definite acquisition enters.
    $this->host->deliver(PartArrived::class, $wrapped);
    self::assertSame($version + 1, $this->row($id)->version, 'the retried resume saved the partial gather');
  }

  #[Group('lock.reentrant-balance')]
  #[TestDox('lock.reentrant-balance: the timeout path holds the lock and with_process acquires it again; one backend acquisition, held_count() === 0 at the end')]
  public function test_lock_reentrant_balance(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_PROCEED));
    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $lock = $processes->worker()->lock();
    $key = $processes->lock_key($id);

    $nested = null;
    ProcessJournal::$on_send = static function (StepCommand $c) use ($lock, $key, &$nested): void {
      if ($c->label === 'assemble') {
        // inside the timeout wake: a with_process-style nested acquisition
        $handle = $lock->acquire($key, 1.0);
        $nested = $lock->held_count();
        $lock->release($handle);
      }
    };
    $acquisitions = $processes->lock_acquisitions();

    $processes->worker()->runner()->handle_timeout($id, 1);

    self::assertSame(2, $nested, 'the nested acquisition re-entered the held lock');
    self::assertSame($acquisitions + 1, $processes->lock_acquisitions(), 'the backend saw one acquisition for the wake');
    self::assertSame(0, $lock->held_count(), 'balanced');
    self::assertSame(1, ProcessJournal::runs('assemble:'), 'the PROCEED timeout resumed with no parts gathered');
    self::assertSame('completed', $this->row($id)->status);
  }
}
