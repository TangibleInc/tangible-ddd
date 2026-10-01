<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\Process\CancellableJobProcess;
use TangibleDDD\Conformance\Fixtures\Process\ChildPurged;
use TangibleDDD\Conformance\Fixtures\Process\ChildrenFirstProcess;
use TangibleDDD\Conformance\Fixtures\Process\JobFinished;
use TangibleDDD\Conformance\Fixtures\Process\KeyedJobProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\Fixtures\Process\StepNote;
use TangibleDDD\Conformance\Fixtures\Process\WidgetScrapped;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Process\AwaitRoute;
use TangibleDDD\Runtime\Scheduling\WakeKind;

/**
 * D3 awaits for TXP process-kernel (CR-W4C4-1; register 3.8 "Ordering",
 * 3.11 D3): a keyed await on a minted ref persisted before dispatch with a
 * register-then-check precheck, any-of with a cancellation fact, and an
 * AwaitAll over a checkpointed dynamic key set. Needs ProcessHost.
 *
 * Every fact is delivered through the host's delivery runner
 * (HostFixture::deliver), so the host's resume subscriber, its
 * find_waiting_for() lookup (column store or route index) and accepts() all
 * take part.
 */
abstract class AwaitScenarios extends ProcessScenarioCase {

  #[Group('process.await-keyed-precheck')]
  #[TestDox('process.await-keyed-precheck: a keyed await on a minted ref is persisted with its checkpoint before dispatch, only its key resumes it, and a precheck absorbs a fact that committed before suspension')]
  public function test_process_await_keyed_precheck(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [JobFinished::class]);

    // At dispatch of the job order: the await and the checkpoint are
    // already committed, keyed on the ref the order carries.
    $atDispatch = [];
    ProcessJournal::$on_send = function (StepCommand $c) use ($processes, &$atDispatch): void {
      if ($c->label !== 'order') {
        return;
      }
      $ids = $processes->process_ids(KeyedJobProcess::class);
      $id = end($ids);
      $stored = $processes->process_store()->find($id);
      $atDispatch[$c->widget_id] = [
        'status' => $processes->process_row($id)?->status,
        'routes' => $stored?->await_routes(),
        'checkpoint' => $stored?->checkpoint_for('order'),
        'alarms' => count($this->intents($id, WakeKind::Timeout)),
      ];
      if (end(ProcessJournal::$steps) === 'order:w-3') {
        // The job finishes synchronously: its owner commits the result
        // before the step returns (the late fact is delivered later).
        ProcessJournal::mark_row(KeyedJobProcess::done_row($c->widget_id));
      }
    };

    // 1. Persisted before dispatch, keyed on the minted ref.
    $a = $this->start(new KeyedJobProcess('w-1'));
    $b = $this->start(new KeyedJobProcess('w-2'));
    [$refA, $refB] = ProcessJournal::widgets('order');
    self::assertNotSame($refA, $refB, 'each process mints its own ref');
    foreach ([$refA, $refB] as $ref) {
      self::assertSame('suspended', $atDispatch[$ref]['status'], 'the await committed before the step\'s commands dispatched');
      self::assertEquals([new AwaitRoute(JobFinished::class, $ref)], $atDispatch[$ref]['routes'], 'keyed on the ref the order carries');
      self::assertInstanceOf(StepNote::class, $atDispatch[$ref]['checkpoint'], 'the step checkpoint is persisted with the await');
      self::assertSame($ref, $atDispatch[$ref]['checkpoint']->text);
      self::assertSame(1, $atDispatch[$ref]['alarms'], 'and its alarm intent');
    }

    // 2. Only the fact carrying the minted key resumes; it resumes only its process.
    $versionA = $this->row($a)->version;
    self::assertTrue($this->deliver(new JobFinished('job-nobody-minted'))->is_complete());
    self::assertSame('suspended', $this->row($a)->status);
    self::assertSame($versionA, $this->row($a)->version, 'a foreign key wrote nothing');

    self::assertTrue($this->deliver(new JobFinished($refA))->is_complete());
    self::assertSame('completed', $this->row($a)->status);
    self::assertSame('suspended', $this->row($b)->status, 'the other key\'s process is untouched');
    self::assertSame(1, ProcessJournal::runs('record:w-1:fact'));
    self::assertSame([], $this->intents($a), 'the alarm is cancelled with the resume');

    self::assertTrue($this->deliver(new JobFinished($refB))->is_complete());
    self::assertSame('completed', $this->row($b)->status);

    // 3. Register-then-check: the result committed during the dispatch; the
    //    precheck after registration sees it and resumes in place.
    $c = $this->start(new KeyedJobProcess('w-3'));
    $refC = ProcessJournal::widgets('order')[2];
    self::assertSame('suspended', $atDispatch[$refC]['status'], 'registered first');
    self::assertSame('completed', $this->row($c)->status, 'then checked: resumed in the same wake');
    self::assertSame(1, ProcessJournal::runs('record:w-3:precheck'));
    self::assertSame([], $this->intents($c), 'the alarm is cancelled in the resuming save');
    $versionC = $this->row($c)->version;

    // The fact the precheck stood in for arrives late: absorbed quietly.
    $late = $this->deliver(new JobFinished($refC));
    self::assertTrue($late->is_complete(), 'no error, no retry');
    self::assertFalse($late->needs_retry());
    self::assertSame($versionC, $this->row($c)->version, 'the late fact wrote nothing');
    self::assertSame(0, ProcessJournal::runs('record:w-3:fact'));
    self::assertSame(1, ProcessJournal::runs('record:w-3:precheck'));
  }

  #[Group('process.await-any-cancellation')]
  #[TestDox('process.await-any-cancellation: an any-of await resumes on its keyed answer; a cancellation fact compensates every process it cancels and no other; late facts are no-ops')]
  public function test_process_await_any_cancellation(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [JobFinished::class, WidgetScrapped::class]);

    $a = $this->start(new CancellableJobProcess('w-1'));
    $b = $this->start(new CancellableJobProcess('w-1'));
    $c = $this->start(new CancellableJobProcess('w-2'));
    [$refA, , $refC] = ProcessJournal::widgets('sync');
    foreach ([$a, $b, $c] as $id) {
      self::assertSame('suspended', $this->row($id)->status);
      self::assertCount(1, $this->intents($id, WakeKind::Timeout), 'the any-of alarm is a durable intent');
    }

    // The cancellation reaches every process of its widget.
    self::assertTrue($this->deliver(new WidgetScrapped('w-1'))->is_complete());

    foreach ([$a, $b] as $id) {
      self::assertSame('failed', $this->row($id)->status, "#$id cancelled");
      self::assertSame([], $this->intents($id), "#$id: no intent left");
    }
    $undone = array_values(array_filter(ProcessJournal::$steps, static fn (string $s) => str_starts_with($s, 'undo_prepare:w-1:')));
    self::assertCount(2, $undone, 'each cancelled process compensated its completed step once');
    self::assertStringContainsString('Cancelled by WidgetScrapped', $undone[0]);
    self::assertSame('suspended', $this->row($c)->status, 'another widget\'s process is untouched');
    self::assertSame(0, ProcessJournal::runs('finish_sync:w-1'));

    // A late answer to a cancelled process changes nothing.
    $versionA = $this->row($a)->version;
    self::assertTrue($this->deliver(new JobFinished($refA))->is_complete());
    self::assertSame('failed', $this->row($a)->status, 'no resurrection after cancellation');
    self::assertSame($versionA, $this->row($a)->version);

    // The surviving process resumes on its own answer.
    self::assertTrue($this->deliver(new JobFinished($refC))->is_complete());
    self::assertSame('completed', $this->row($c)->status);
    self::assertSame(1, ProcessJournal::runs('finish_sync:w-2'));
    self::assertSame([], $this->intents($c));

    // A cancellation after completion is a no-op.
    $versionC = $this->row($c)->version;
    self::assertTrue($this->deliver(new WidgetScrapped('w-2'))->is_complete());
    self::assertSame('completed', $this->row($c)->status);
    self::assertSame($versionC, $this->row($c)->version);
    self::assertSame(0, ProcessJournal::runs('undo_prepare:w-2'), 'nothing compensated');
  }

  #[Group('process.await-all-dynamic')]
  #[TestDox('process.await-all-dynamic: an AwaitAll over a key set computed and checkpointed at step time resumes once every key arrived; foreign, unknown and duplicate keys write nothing; an empty set does not suspend')]
  public function test_process_await_all_dynamic(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [ChildPurged::class]);

    $p = $this->start(new ChildrenFirstProcess('w-1', ['c1', 'c2', 'c3']));
    $q = $this->start(new ChildrenFirstProcess('w-2', ['c1']));

    $keys = ['w-1:c1', 'w-1:c2', 'w-1:c3'];
    self::assertSame('suspended', $this->row($p)->status);
    $stored = $processes->process_store()->find($p);
    $checkpoint = $stored?->checkpoint_for('children_first');
    self::assertInstanceOf(StepNote::class, $checkpoint, 'the computed key set is checkpointed with the await');
    self::assertSame($keys, $checkpoint->items);
    self::assertEquals(array_map(static fn (string $k) => new AwaitRoute(ChildPurged::class, $k), $keys), $stored->await_routes());
    self::assertCount(1, $this->intents($p, WakeKind::Timeout));

    self::assertTrue($this->deliver(new ChildPurged('w-1:c2'))->is_complete());
    self::assertSame('suspended', $this->row($p)->status, 'a partial arrival keeps waiting');
    self::assertEquals(
      [new AwaitRoute(ChildPurged::class, 'w-1:c1'), new AwaitRoute(ChildPurged::class, 'w-1:c3')],
      $processes->process_store()->find($p)?->await_routes(),
      'the outstanding keys shrink as children arrive',
    );
    self::assertCount(1, $this->intents($p, WakeKind::Timeout), 'the alarm is not re-delayed by a partial arrival');
    $version = $this->row($p)->version;

    self::assertTrue($this->deliver(new ChildPurged('w-1:c2'))->is_complete(), 'a duplicate child');
    self::assertTrue($this->deliver(new ChildPurged('w-1:c9'))->is_complete(), 'a child outside the set');
    self::assertSame($version, $this->row($p)->version, 'neither wrote anything');

    self::assertTrue($this->deliver(new ChildPurged('w-2:c1'))->is_complete());
    self::assertSame('completed', $this->row($q)->status, 'the other process\'s set is its own');
    self::assertSame('suspended', $this->row($p)->status);
    self::assertSame($version, $this->row($p)->version);

    $this->deliver(new ChildPurged('w-1:c1'));
    $this->deliver(new ChildPurged('w-1:c3'));
    self::assertSame('completed', $this->row($p)->status);
    self::assertSame(['purge_self:w-1:w-1:c2,w-1:c1,w-1:c3'], array_values(array_filter(
      ProcessJournal::$steps,
      static fn (string $s) => str_starts_with($s, 'purge_self:w-1:'),
    )), 'resumed once, with every key');
    self::assertSame([], $this->intents($p), 'the alarm is cancelled');

    // An empty dynamic set completes without suspending.
    $e = $this->start(new ChildrenFirstProcess('w-3', []));
    self::assertSame('completed', $this->row($e)->status);
    self::assertSame(1, ProcessJournal::runs('purge_self:w-3:'));
    self::assertSame([], $this->intents($e));
  }

  /** One delivery of $fact under a fresh event id, through the host's delivery runner. */
  protected function deliver(IIntegrationEvent $fact): DeliveryOutcome {
    return $this->host->deliver(get_class($fact), self::wrap($fact, Uuid::v4()));
  }
}
