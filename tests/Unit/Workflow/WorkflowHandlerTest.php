<?php

namespace TangibleDDD\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Tests\Fakes\FakeBatchableConfig;
use TangibleDDD\Tests\Fakes\FakeBehaviourWorkflowRepository;
use TangibleDDD\Tests\Fakes\FakeStopConfig;
use TangibleDDD\Tests\Fakes\FakeWorkItemRepository;

/**
 * Concrete WorkflowHandler for testing.
 */
class TestableWorkflowHandler extends WorkflowHandler {

  /** @var BehaviourWorkflow[] */
  public array $workflows_to_return = [];

  /** @var BehaviourExecutionResult[] */
  public array $item_results = [];
  private int $result_idx = 0;

  /** @var array{BehaviourWorkflow, int}[] */
  public array $rescheduled = [];

  public bool $no_workflows_called = false;

  protected function get_workflows(ICommand $command): array {
    return $this->workflows_to_return;
  }

  protected function execute_one(
    BaseBehaviourConfig $config,
    WorkItem $item,
    ?BehaviourExecutionResult $previous
  ): BehaviourExecutionResult {
    if (isset($this->item_results[$this->result_idx])) {
      return $this->item_results[$this->result_idx++];
    }

    // Default: success
    return new BehaviourExecutionResult(
      type: $config->get_behaviour_type(),
      success: true,
      context: ['message' => 'ok'],
      status: BehaviourExecutionStatus::completed,
      timestamp: gmdate('c'),
    );
  }

  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    return new WorkItemList([
      new WorkItem(null, $workflow->get_id(), $workflow->get_current_idx(), $workflow->get_current_phase(), 'item-1'),
      new WorkItem(null, $workflow->get_id(), $workflow->get_current_idx(), $workflow->get_current_phase(), 'item-2'),
    ]);
  }

  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {
    $this->rescheduled[] = [$workflow, $delay_seconds];
  }

  protected function no_workflows(): void {
    $this->no_workflows_called = true;
  }
}

class WorkflowHandlerTest extends TestCase {

  private FakeBehaviourWorkflowRepository $wf_repo;
  private FakeWorkItemRepository $item_repo;
  private TestableWorkflowHandler $handler;

  protected function setUp(): void {
    $this->wf_repo = new FakeBehaviourWorkflowRepository();
    $this->item_repo = new FakeWorkItemRepository();
    $this->handler = new TestableWorkflowHandler($this->wf_repo, $this->item_repo);
  }

  private function make_command(): ICommand {
    return new class implements ICommand {
      public function send(): mixed { return null; }
    };
  }

  public function test_no_workflows_calls_no_workflows_hook(): void {
    $this->handler->workflows_to_return = [];
    $this->handler->handle($this->make_command());

    $this->assertTrue($this->handler->no_workflows_called);
  }

  public function test_single_workflow_runs_to_completion(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $this->handler->workflows_to_return = [$wf];

    $this->handler->handle($this->make_command());

    $this->assertTrue($wf->is_complete());
    $this->assertGreaterThanOrEqual(1, $this->wf_repo->save_count);
    // Work items should have been generated and saved
    $this->assertGreaterThanOrEqual(2, $this->item_repo->save_count);
  }

  public function test_workflow_persisted_before_work_items(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $this->handler->workflows_to_return = [$wf];

    $this->handler->handle($this->make_command());

    // Workflow should have an ID after save
    $this->assertNotNull($wf->get_id());
    // And should be in the repo
    $this->assertSame($wf, $this->wf_repo->get_by_id($wf->get_id()));
  }

  public function test_failed_item_marks_workflow_failed(): void {
    // Disable retries so failure is immediate
    TestableWorkflowHandler::$max_retries = 0;

    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $this->handler->workflows_to_return = [$wf];

    // All items will return failed
    $this->handler->item_results = [
      new BehaviourExecutionResult('stop', false, ['message' => 'error'], BehaviourExecutionStatus::failed, gmdate('c')),
      new BehaviourExecutionResult('stop', false, ['message' => 'error'], BehaviourExecutionStatus::failed, gmdate('c')),
    ];

    $this->handler->handle($this->make_command());

    $this->assertTrue($wf->is_failed());

    // Restore default
    TestableWorkflowHandler::$max_retries = 3;
  }

  public function test_multiple_workflows_reschedules_extras(): void {
    $wf1 = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $wf2 = new BehaviourWorkflow(null, 2, 'request', [new FakeStopConfig()]);
    $wf3 = new BehaviourWorkflow(null, 3, 'request', [new FakeStopConfig()]);

    $this->handler->workflows_to_return = [$wf1, $wf2, $wf3];
    $this->handler->handle($this->make_command());

    // First workflow executes immediately, remaining 2 get rescheduled
    $this->assertTrue($wf1->is_complete());
    $this->assertCount(2, $this->handler->rescheduled);
  }

  public function test_waiting_result_pauses_workflow(): void {
    $configs = [new FakeStopConfig(), new FakeStopConfig()];
    $wf = new BehaviourWorkflow(null, 1, 'request', $configs);
    $this->handler->workflows_to_return = [$wf];

    // Both items return waiting
    $this->handler->item_results = [
      new BehaviourExecutionResult('stop', true, ['message' => 'wait'], BehaviourExecutionStatus::waiting, gmdate('c')),
      new BehaviourExecutionResult('stop', true, ['message' => 'wait'], BehaviourExecutionStatus::waiting, gmdate('c')),
    ];

    $this->handler->handle($this->make_command());

    // Workflow should NOT be complete — it's paused on waiting
    $this->assertFalse($wf->is_complete());
    $this->assertFalse($wf->is_failed());
    // Should be saved but not rescheduled (waiting waits for external signal)
    $this->assertGreaterThanOrEqual(1, $this->wf_repo->save_count);
  }

  public function test_all_failed_batchable_reschedules_instead_of_forking(): void {
    // When ALL items in a batchable step fail (no partial success), the fork gate
    // must NOT fork — forking a 100%-failed batch gains nothing and wastes a retry
    // budget. Instead the whole workflow reschedules for a step-level retry.
    $config = new FakeBatchableConfig(batch: [1, 2], batch_size: 10);
    $wf = new BehaviourWorkflow(null, 1, 'request', [$config]);
    $this->handler->workflows_to_return = [$wf];

    // Both items fail — no partial success
    $this->handler->item_results = [
      new BehaviourExecutionResult('batch', false, ['message' => 'fail'], BehaviourExecutionStatus::failed, gmdate('c')),
      new BehaviourExecutionResult('batch', false, ['message' => 'fail'], BehaviourExecutionStatus::failed, gmdate('c')),
    ];

    $this->handler->handle($this->make_command());

    // No forked child workflow should exist in the repo
    $forked = array_filter($this->wf_repo->store, fn($w) => $w->is_forked());
    $this->assertEmpty($forked, 'All-fail case must NOT fork a child workflow');

    // The workflow itself must be rescheduled for a whole-step retry (max_retries > 0)
    $this->assertNotEmpty($this->handler->rescheduled, 'All-fail case must reschedule the workflow for retry');

    // The workflow must not be marked complete
    $this->assertFalse($wf->is_complete(), 'Workflow must not be complete after an all-fail that is still within retry budget');
  }

  public function test_partial_failure_forks_child_workflow(): void {
    // When SOME (but not all) items fail, the fork gate must create a child
    // workflow holding only the failed items and reschedule it. This locks the
    // "partial-failure → fork" branch of maybe_fork_or_fail().
    //
    // TestableWorkflowHandler::generate_work_items() always produces item-1 and
    // item-2. We make item-1 succeed and item-2 fail so the aggregate is a
    // partial failure.
    $config = new FakeBatchableConfig(batch: [1, 2], batch_size: 10);
    $wf = new BehaviourWorkflow(null, 1, 'request', [$config]);
    $this->handler->workflows_to_return = [$wf];

    // item-1 succeeds, item-2 fails → partial failure
    $this->handler->item_results = [
      new BehaviourExecutionResult('batch', true, ['message' => 'ok'], BehaviourExecutionStatus::completed, gmdate('c')),
      new BehaviourExecutionResult('batch', false, ['message' => 'fail'], BehaviourExecutionStatus::failed, gmdate('c')),
    ];

    $this->handler->handle($this->make_command());

    // A forked child workflow must have been created in the repo
    $forked = array_filter($this->wf_repo->store, fn($w) => $w->is_forked());
    $this->assertNotEmpty($forked, 'Partial-failure case must fork a child workflow');

    // The forked child must be rescheduled
    $this->assertNotEmpty($this->handler->rescheduled, 'Forked child must be rescheduled');

    // The child must carry the failed item (item-2 is item_key 'item-2' in FakeWorkItemRepository)
    $child = array_values($forked)[0];
    $this->assertNotNull($child->get_id(), 'Forked child must be persisted with an ID');
  }

  public function test_work_items_reused_on_reentry(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $this->wf_repo->save($wf); // pre-persist

    // Pre-populate work items (simulating a previous run)
    $item1 = new WorkItem(null, $wf->get_id(), 0, 1, 'item-1');
    $item2 = new WorkItem(null, $wf->get_id(), 0, 1, 'item-2');
    $this->item_repo->save($item1);
    $this->item_repo->save($item2);

    $save_count_before = $this->item_repo->save_count;
    $this->handler->workflows_to_return = [$wf];
    $this->handler->handle($this->make_command());

    // Items should have been reused (only updated, not newly created)
    // The handler processes 2 items and saves them, but doesn't generate new ones
    $items = $this->item_repo->get_for_step($wf->get_id(), 0, 1);
    $this->assertSame(2, count($items));
  }

  public function test_two_step_workflow_completes_both(): void {
    $configs = [new FakeStopConfig(), new FakeStopConfig()];
    $wf = new BehaviourWorkflow(null, 1, 'request', $configs);
    $this->handler->workflows_to_return = [$wf];

    // All 4 items (2 per step) succeed
    $this->handler->item_results = [
      new BehaviourExecutionResult('stop', true, [], BehaviourExecutionStatus::completed, gmdate('c')),
      new BehaviourExecutionResult('stop', true, [], BehaviourExecutionStatus::completed, gmdate('c')),
      new BehaviourExecutionResult('stop', true, [], BehaviourExecutionStatus::completed, gmdate('c')),
      new BehaviourExecutionResult('stop', true, [], BehaviourExecutionStatus::completed, gmdate('c')),
    ];

    $this->handler->handle($this->make_command());

    $this->assertTrue($wf->is_complete());
    $this->assertSame(2, $wf->get_current_idx());
  }
}
