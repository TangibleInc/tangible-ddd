<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionOutcome;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\Workflow\CronEntryDue;
use TangibleDDD\Conformance\Fixtures\Workflow\CronExportWorkflow;
use TangibleDDD\Conformance\WorkflowHost;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;

/**
 * D10 workflow ignition from a fact (register section 4
 * `workflow.fact-ignition-once`, 3.11 D10). Needs WorkflowHost (CR-W4C4-4).
 */
abstract class WorkflowScenarios extends ConformanceTestCase {

  protected const CONSUMER_PREFIX = 'conformance';

  protected function setUp(): void {
    CronExportWorkflow::reset();
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    CronExportWorkflow::reset();
  }

  #[Group('workflow.fact-ignition-once')]
  #[TestDox('workflow.fact-ignition-once: the same CronEntryDue delivered twice, and two cron ticks in one minute, make exactly one workflow run; the next minute makes another; a failed start is restarted once')]
  public function test_workflow_fact_ignition_once(): void {
    $workflows = $this->workflows();
    $igniter = $workflows->workflowIgniter();
    $workflow = new CronExportWorkflow($workflows->workflowRepository());
    $igniter->register($workflow, $this->host->subscriptions(), static::CONSUMER_PREFIX);

    // 1. The same fact, delivered twice.
    $tick = new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00');
    $eventId = Uuid::v4();
    $wrapped = self::wrap($tick, $eventId);
    self::assertTrue($this->host->deliver(CronEntryDue::class, $wrapped)->isComplete());
    self::assertTrue($this->host->deliver(CronEntryDue::class, $wrapped)->isComplete());
    [$first] = $this->runsOf('nightly');
    self::assertSame([$first], CronExportWorkflow::$started, 'one workflow, one run');

    // The ignition ledger is the gate, not only the delivery ledger: another
    // worker or consumer igniting the same fact loses the claim.
    $again = $igniter->ignite($workflow, $tick, $eventId);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $again->outcome);
    self::assertSame($first, $again->workflowId, 'the loser is told the winner\'s workflow');
    $entry = $workflows->workflowIgnitionLedger()->find($workflow->ignition_key($tick, $eventId));
    self::assertNotNull($entry);
    self::assertSame($first, $entry->workflowId);

    // 2. A second cron tick in the same minute (another fact, another event id).
    self::assertTrue($this->tick('nightly', '2026-10-01T12:00:40+00:00')->isComplete());
    self::assertSame([$first], $this->runsOf('nightly'), 'still one workflow for the minute');
    self::assertSame([$first], CronExportWorkflow::$started);

    // 3. The next minute ignites the next run.
    self::assertTrue($this->tick('nightly', '2026-10-01T12:01:00+00:00')->isComplete());
    $runs = $this->runsOf('nightly');
    self::assertCount(2, $runs);
    self::assertSame($runs, CronExportWorkflow::$started);

    // A declined fact ignites nothing.
    self::assertTrue($this->tick('skip', '2026-10-01T12:01:00+00:00')->isComplete());
    self::assertSame([], $this->runsOf('skip'));

    // 4. A start that fails: the ignition stands, the delivery retries, and
    //    the retry starts the same workflow exactly once.
    CronExportWorkflow::$failStarts = 1;
    $failing = self::wrap(new CronEntryDue('nightly', '2026-10-01T12:02:00+00:00'), Uuid::v4());
    $outcome = $this->host->deliver(CronEntryDue::class, $failing);
    self::assertTrue($outcome->needsRetry(), 'the failed start fails the ignition subscriber');
    $runs = $this->runsOf('nightly');
    self::assertCount(3, $runs, 'the workflow ignited once');
    self::assertCount(2, CronExportWorkflow::$started, 'but did not run');

    self::assertTrue($this->host->deliver(CronEntryDue::class, $failing)->isComplete());
    self::assertTrue($this->tick('nightly', '2026-10-01T12:02:30+00:00')->isComplete());
    self::assertSame($runs, $this->runsOf('nightly'), 'no second workflow for 12:02');
    self::assertSame($runs, CronExportWorkflow::$started, 'the 12:02 workflow ran exactly once');
  }

  protected function workflows(): WorkflowHost {
    if (!$this->host instanceof WorkflowHost) {
      $this->skipForChangeRequest('CR-W4C4-4', 'the host fixture does not implement WorkflowHost yet');
    }
    return $this->host;
  }

  private function tick(string $entry, string $dueAt): DeliveryOutcome {
    return $this->host->deliver(CronEntryDue::class, self::wrap(new CronEntryDue($entry, $dueAt), Uuid::v4()));
  }

  /** @return list<int> ids of the workflows ignited for $entry, ascending */
  private function runsOf(string $entry): array {
    $ids = array_map(
      static fn ($w): int => (int) $w->get_id(),
      $this->workflows()->workflowRepository()->get_by_ref_id(1, CronExportWorkflow::refType($entry)),
    );
    sort($ids);
    return $ids;
  }
}
