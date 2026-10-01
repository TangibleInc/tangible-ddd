<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\HopWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\OrderedWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PackWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\Fixtures\Process\WidgetOrdered;
use TangibleDDD\Conformance\Fixtures\Process\WidgetPacked;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * The process.* scenarios that every host runs (register section 4, 3.8,
 * 5.3): ignition dedup, manual starts in a drain, the timeout/fact race,
 * await before dispatch, durable intents and stale wakeups. The
 * multi-process ids live in FreshProcessScenarios and ConcurrencyScenarios.
 */
abstract class ProcessScenarios extends ProcessScenarioCase {

  #[Group('process.ignition-race')]
  #[TestDox('process.ignition-race: two workers deliver the same igniting fact concurrently; one process row, the loser runs no step')]
  public function test_process_ignition_race(): void {
    $processes = $this->processes();
    $processes->wire_processes([[OrderedWidgetProcess::class, WidgetOrdered::class]], []);
    $wrapped = self::wrap(new WidgetOrdered('w-1'), Uuid::v4());

    // Worker 1 has inserted the ignited row and is about to lock it for the
    // first step; worker 2 delivers the same fact right then.
    $second = null;
    $processes->before_next_lock(function () use ($processes, $wrapped, &$second): void {
      $second = $processes->worker(2)->deliver(WidgetOrdered::class, $wrapped);
    });
    $first = $processes->worker(1)->deliver(WidgetOrdered::class, $wrapped);

    self::assertInstanceOf(DeliveryOutcome::class, $second, 'the two deliveries overlapped');
    self::assertTrue($first->is_complete());
    self::assertTrue($second->is_complete(), 'the loser\'s ignition returned quietly (AlreadyIgnited)');
    self::assertCount(1, $processes->process_ids(OrderedWidgetProcess::class), 'exactly one process row');
    self::assertSame(1, ProcessJournal::runs('open:w-1'), 'the loser ran no step');
    self::assertSame(['open'], ProcessJournal::labels());
  }

  #[Group('process.manual-start-in-drain')]
  #[TestDox('process.manual-start-in-drain: two manual start() calls inside a fact drain make two rows, ignition_key NULL, never deduped; the #[StartsOn] ignition still ignites once')]
  public function test_process_manual_start_in_drain(): void {
    $processes = $this->processes();
    $processes->wire_processes([[OrderedWidgetProcess::class, WidgetOrdered::class]], []);
    $runner = $processes->worker()->runner();
    $this->host->subscriptions()->add(new Subscriber('conformance.manual-starter', Subscriber::LISTENER, WidgetOrdered::class,
      static function () use ($runner): void {
        $runner->start(new OrderedWidgetProcess('w-1'));
        $runner->start(new OrderedWidgetProcess('w-1'));
      }));
    $eventId = Uuid::v4();
    $fact = new WidgetOrdered('w-1');

    $outcome = $this->host->deliver(WidgetOrdered::class, self::wrap($fact, $eventId));
    $processes->worker()->drain_once(); // runs deferred first steps (sf); a no-op where starts are in-band

    self::assertTrue($outcome->is_complete());
    $ids = $processes->process_ids(OrderedWidgetProcess::class);
    self::assertCount(3, $ids, 'two manual starts and one ignition');
    [$manual1, $manual2, $ignited] = array_map(fn (int $id) => $this->row($id), $ids);
    foreach ([$manual1, $manual2] as $manual) {
      self::assertNull($manual->ignition_key, 'a manual start is never deduped (ignition_key NULL)');
      self::assertSame($eventId, $manual->ignited_by, 'the drain\'s fact is recorded as the igniter');
    }
    self::assertNotNull($ignited->ignition_key, 'the #[StartsOn] ignition carries its key');
    self::assertSame($eventId, $ignited->ignited_by);
    self::assertSame(3, ProcessJournal::runs('open:w-1'));

    // A later ignition of the class by the same fact is still deduped.
    $runner->ignite(OrderedWidgetProcess::class, $fact, $eventId);
    self::assertCount(3, $processes->process_ids(OrderedWidgetProcess::class), 'still ignited once');
  }

  #[Group('process.timeout-vs-event')]
  #[TestDox('process.timeout-vs-event: when the timeout and the awaited fact race exactly one applies, and a compensated process is never resurrected')]
  public function test_process_timeout_vs_event(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [PartArrived::class]);

    // 1. The race: the due timeout wake is about to lock; the last part
    //    arrives on worker 2 at that moment.
    $raced = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    self::assertSame('suspended', $this->row($raced)->status);
    self::assertCount(1, $this->intents($raced, WakeKind::Timeout), 'the alarm is a durable intent');
    $this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-1', 'a'), Uuid::v4()));
    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);

    $late = null;
    $processes->before_next_lock(function () use ($processes, &$late): void {
      $late = $processes->worker(2)->deliver(PartArrived::class, self::wrap(new PartArrived('w-1', 'b'), Uuid::v4()));
    });
    $processes->worker()->drain_once();
    if ($late !== null && $late->needs_retry()) {
      // the fact lost the lock: the delivery runner retries it
      $this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-1', 'b'), Uuid::v4()));
    }

    $resumed = ProcessJournal::runs('assemble:w-1:a,w-1:b');
    $compensated = ProcessJournal::runs('undo_prepare');
    self::assertNotNull($late, 'the fact and the timeout overlapped');
    self::assertSame(1, $resumed + $compensated, 'exactly one of resume or timeout applied');
    self::assertSame($resumed === 1 ? 'completed' : 'failed', $this->row($raced)->status);
    self::assertSame([], $this->intents($raced), 'no intent left behind');

    // 2. Timeout first, then the facts: compensated, never resurrected.
    $timedOut = $this->start(new GatherPartsProcess('w-2', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $processes->worker()->drain_once();
    self::assertSame('failed', $this->row($timedOut)->status);
    $version = $this->row($timedOut)->version;

    foreach (['a', 'b'] as $part) {
      $this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-2', $part), Uuid::v4()));
    }

    self::assertSame(0, ProcessJournal::runs('assemble:w-2:a,w-2:b'), 'no resurrection after compensation');
    self::assertSame('failed', $this->row($timedOut)->status);
    self::assertSame($version, $this->row($timedOut)->version, 'the late facts wrote nothing');
  }

  #[Group('process.await-before-dispatch')]
  #[TestDox('process.await-before-dispatch: the awaited fact delivered synchronously inside the step\'s dispatch still resumes the process')]
  public function test_process_await_before_dispatch(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [WidgetPacked::class]);
    $statusAtDispatch = [];
    $id = null;
    ProcessJournal::$on_send = function (StepCommand $c) use ($processes, &$statusAtDispatch, &$id): void {
      if ($id !== null && in_array($c->label, ['ask', 'ask-2'], true)) {
        $statusAtDispatch[$c->label] = $processes->process_row($id)?->status;
      }
      if ($c->label === 'ask') {
        // the command's handler causes the awaited fact synchronously
        $processes->worker()->deliver(WidgetPacked::class, self::wrap(new WidgetPacked('w-1'), Uuid::v4()));
      }
    };
    $process = new PackWidgetProcess('w-1');
    $this->processes()->worker()->runner()->start($process);
    $id = (int) $process->get_id();
    if ($this->row($id)->status === 'scheduled') {
      $processes->worker()->drain_once(); // deferred first step (sf)
    }

    self::assertSame(['ask', 'ship'], ProcessJournal::$steps, 'resumed although the fact arrived before the dispatch returned');
    self::assertSame(['ask', 'ship', 'ask-2'], ProcessJournal::labels());
    self::assertSame('completed', $this->row($id)->status);
    self::assertSame(0, $processes->worker()->lock()->held_count());
  }

  #[Group('process.intent-survives-queue-failure')]
  #[TestDox('process.intent-survives-queue-failure: the process save commits, the wake transport is down; the intent row remains and a later run wakes the process')]
  public function test_process_intent_survives_queue_failure(): void {
    $processes = $this->processes();
    // Armed before the start: hosts hand intents over at schedule time (wp),
    // at relay time (sf) or at execution (pdo, mem); the FIRST hand-off
    // after a committed save fails, wherever it happens. HopWidgetProcess
    // always leaves a Continue intent behind its committed save (the start
    // under StartMode::Deferred, else the #[Async] second step).
    $processes->fail_next_handoff('wake transport down');
    $process = new HopWidgetProcess('w-1');
    $processes->worker()->runner()->start($process);
    $id = (int) $process->get_id();

    $processes->worker()->drain_once();

    self::assertSame('scheduled', $this->row($id)->status, 'the save committed; the process waits on its intent');
    self::assertSame(0, ProcessJournal::runs('second'), 'the failed hand-off woke nothing');
    $survivor = $this->intent_keys($id, WakeKind::Continue);
    self::assertCount(1, $survivor, 'the intent row remains');

    for ($run = 0; $run < 3 && $this->row($id)->status !== 'completed'; $run++) {
      $this->host->advance_clock(self::PAST_WAKE_BACKOFF);
      $processes->worker()->drain_once();
    }

    self::assertSame('completed', $this->row($id)->status, 'a later run woke the process');
    self::assertSame(1, ProcessJournal::runs('first'));
    self::assertSame(1, ProcessJournal::runs('second'));
    self::assertSame([], $this->intents($id));
  }

  #[Group('process.stale-wakeup')]
  #[TestDox('process.stale-wakeup: a continuation for a step already passed, a duplicate continuation and a stale timeout are no-ops')]
  public function test_process_stale_wakeup(): void {
    $processes = $this->processes();
    $id = $this->start(new HopWidgetProcess('w-1'));
    [$continuation] = $this->intents($id, WakeKind::Continue);
    $processes->worker()->drain_once();
    self::assertSame(['first', 'second'], ProcessJournal::$steps);
    self::assertSame('completed', $this->row($id)->status);
    $version = $this->row($id)->version;
    $consumer = $processes->consumer_prefix();
    $now = $this->host->clock()->now();
    $runner = $processes->worker()->runner();

    $runner->wake(WakeupIntent::continuation($consumer, $id, 0, $now));       // a step already passed
    $runner->wake($continuation);                                             // the one that already ran
    $runner->wake(WakeupIntent::timeout($consumer, $id, 1, $now));            // an alarm nobody awaits any more

    // ... and through the host's drain, as a redelivered queue message would arrive.
    $stale = WakeupIntent::continuation($consumer, $id, 1, $now, 'stale-copy');
    $this->host->boundary()->run(static fn () => $processes->wakeups()->schedule($stale));
    $report = $processes->worker()->drain_once();

    self::assertContains($stale->key, $report->wakes_completed, 'a stale wake completes as a no-op');
    self::assertSame(['first', 'second'], ProcessJournal::$steps, 'no step ran again');
    self::assertSame($version, $this->row($id)->version, 'nothing was written');
    self::assertSame([], $this->intents($id));

    // Stale against a LIVE process: suspended at step 1 of its gather.
    $processes->wire_processes([], [PartArrived::class]);
    $gather = $this->start(new GatherPartsProcess('w-2', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $steps = ProcessJournal::$steps;
    $version = $this->row($gather)->version;

    $runner->wake(WakeupIntent::continuation($consumer, $gather, 1, $now));   // a continuation, but the row is suspended, not scheduled
    $runner->wake(WakeupIntent::timeout($consumer, $gather, 0, $now));        // an alarm for an earlier step

    self::assertSame($steps, ProcessJournal::$steps, 'neither ran a step nor compensated');
    self::assertSame('suspended', $this->row($gather)->status);
    self::assertSame($version, $this->row($gather)->version);
  }
}
