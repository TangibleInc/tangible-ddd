<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\MakeWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Conformance\Support\FreshProcessBoot;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * Scenarios that cross php processes (register section 4; `-` on mem,
 * which runs them only under the `simulated` group). They need the
 * FreshProcesses seam (CR-W3CP-4) on top of ProcessHost. Effects made in a
 * fresh process are read back as scenario rows (Support\FreshProcessBoot,
 * Fixtures\Process\ProcessJournal::bind()).
 */
abstract class FreshProcessScenarios extends ProcessScenarioCase {

  /** Past InMemoryProcessStore's / the register's stranded threshold (15 min, 5.3 step 5). */
  protected const PAST_STRANDED = 901;

  #[Group('relay.fresh-process-pickup')]
  #[TestDox('relay.fresh-process-pickup: process A commits a fact and exits; a fresh process B delivers it, sharing nothing but the database')]
  public function test_relay_fresh_process_pickup(): void {
    $fresh = $this->fresh();
    $effect = FreshProcessBoot::effectRowId('w-1');

    $fresh->publishInFreshProcess(new WidgetRegistered('w-1'), killAfterCommit: false);
    self::assertFalse($this->host->scenarioRows()->has($effect), 'A delivered nothing');

    $b = $fresh->drainInFreshProcess();

    self::assertSame([], $b->errors);
    self::assertTrue($this->host->scenarioRows()->has($effect), 'B delivered it');
    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(1, $stats['accepted']);
    self::assertSame(0, $stats['pending']);

    $again = $fresh->drainInFreshProcess();
    self::assertSame([], $again->relayed, 'nothing left to relay');
    self::assertSame([], $again->errors, 'and nothing delivered twice (a second effect insert would fail)');
  }

  #[Group('relay.crash-after-commit')]
  #[TestDox('relay.crash-after-commit: the process is killed right after COMMIT, before any relay; the fact stays pending and the next run delivers it once')]
  public function test_relay_crash_after_commit(): void {
    $fresh = $this->fresh();
    $effect = FreshProcessBoot::effectRowId('w-1');

    $id = $fresh->publishInFreshProcess(new WidgetRegistered('w-1'), killAfterCommit: true);

    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(1, $stats['pending'], 'the committed fact is still pending');
    self::assertSame(0, $stats['accepted']);
    self::assertNotContains($id, array_map(static fn (TransportedFact $t) => $t->eventId, $this->host->transported()), 'never submitted');

    $next = $fresh->drainInFreshProcess();

    self::assertContains($id, $next->relayed);
    self::assertSame([], $next->errors);
    self::assertTrue($this->host->scenarioRows()->has($effect), 'delivered');

    $again = $fresh->drainInFreshProcess();
    self::assertSame([], $again->relayed);
    self::assertSame([], $again->errors, 'delivered once');
  }

  #[Group('process.crash-mid-step')]
  #[TestDox('process.crash-mid-step: killed after the step\'s command committed, before the checkpoint; the operator view shows it stranded, and the resume re-runs the step with the same deterministic command id')]
  public function test_process_crash_mid_step(): void {
    $fresh = $this->fresh();
    $processes = $this->processes();

    $run = $fresh->startInFreshProcess(new MakeWidgetProcess('w-1'), dieAfterCommand: 'make');

    self::assertTrue($run->died);
    $id = (int) $run->processId;
    $commandId = DeterministicCommandId::forStep($processes->processConsumer(), $id, '0', 0);
    self::assertTrue($this->host->scenarioRows()->has(ProcessJournal::rowId('make', $commandId)), 'the step\'s command committed');
    $row = $this->row($id);
    self::assertSame('running', $row->status, 'no checkpoint: still running at the step');
    self::assertSame(0, $row->stepIndex);

    $this->host->advanceClock(self::PAST_STRANDED);
    $items = $processes->operatorView()->list(Layer::Process);
    self::assertCount(1, $items, 'one stranded item in the operator view');
    self::assertSame((string) $id, $items[0]->key);
    self::assertContains('resume_stranded', $items[0]->repairActions);
    $processes->worker()->drainOnce();
    self::assertSame('running', $this->row($id)->status, 'a running row is reported, never re-run automatically');
    self::assertSame([], ProcessJournal::commandIds('make'));

    // The resume (what the ResumeStrandedProcess repair does, wave 4): a
    // ResumeRetry of the first step at the row's current version.
    $now = $this->host->clock()->now();
    $resume = WakeupIntent::resumeRetry($processes->processConsumer(), $id, 0, 'running', $this->row($id)->version, $now, 'resume-stranded');
    $this->host->boundary()->run(static fn () => $processes->wakeups()->schedule($resume));
    $processes->worker()->drainOnce();

    self::assertSame([$commandId], ProcessJournal::commandIds('make'), 'the step re-ran with the same deterministic command id');
    self::assertSame('completed', $this->row($id)->status);
    self::assertSame([], $processes->operatorView()->list(Layer::Process), 'no longer stranded');
  }

  #[Group('process.fresh-process-resume')]
  #[TestDox('process.fresh-process-resume: P1 suspends; a fresh P2 delivers the awaited fact; a fresh P3 fires the stale timeout; the process completes once and the timeout is a no-op')]
  public function test_process_fresh_process_resume(): void {
    $fresh = $this->fresh();
    $processes = $this->processes();
    $processes->wireProcesses([], [PartArrived::class]);

    $id = $this->start(new GatherPartsProcess('w-1', ['a'], AwaitAll::TIMEOUT_FAIL)); // P1
    self::assertSame('suspended', $this->row($id)->status);
    self::assertCount(1, $this->intents($id, WakeKind::Timeout));

    $p2 = $fresh->deliverInFreshProcess(PartArrived::class, self::wrap(new PartArrived('w-1', 'a'), Uuid::v4()));

    self::assertSame([], $p2->errors);
    self::assertSame('completed', $this->row($id)->status, 'P2 resumed it');
    $assembled = ProcessJournal::rowId('assemble', DeterministicCommandId::forStep($processes->processConsumer(), $id, '2', 0));
    self::assertTrue($this->host->scenarioRows()->has($assembled));
    $version = $this->row($id)->version;

    $this->host->advanceClock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $p3 = $fresh->drainInFreshProcess();

    self::assertSame([], $p3->errors);
    self::assertSame('completed', $this->row($id)->status, 'completed once');
    self::assertSame($version, $this->row($id)->version, 'the stale timeout wrote nothing');
    $undo = ProcessJournal::rowId('undo_prepare', DeterministicCommandId::forStep($processes->processConsumer(), $id, 'prepare', 0, true));
    self::assertFalse($this->host->scenarioRows()->has($undo), 'no compensation');
    self::assertSame([], $this->intents($id), 'no live intent');
  }

  protected function fresh(): FreshProcesses {
    $this->processes();
    if (!$this->host instanceof FreshProcesses) {
      $this->skipForChangeRequest('CR-W3CP-4', 'the host fixture does not implement FreshProcesses yet');
    }
    return $this->host;
  }
}
