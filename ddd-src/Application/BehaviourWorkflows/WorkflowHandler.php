<?php

namespace TangibleDDD\Application\BehaviourWorkflows;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\RescheduleAware;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;

/**
 * Base behaviour workflow runner.
 *
 * - Abstract: apps implement scheduling + execute_one() behaviour logic.
 * - Uses work item ledger (behaviour_workflow_items) for per-item progress tracking.
 */
abstract class WorkflowHandler implements ICommandHandler {
  use RescheduleAware;

  public static int $max_retries = 3;
  public static int $reschedule_interval = 5;

  protected BehaviourWorkflow $current_workflow;

  public function __construct(
    protected readonly IBehaviourWorkflowRepository $workflow_repo,
    protected readonly IWorkItemRepository $item_repo,
  ) {}

  public function handle(ICommand $command): void {
    $this->started_at = time();

    $workflows = $this->get_workflows($command);
    if (empty($workflows)) {
      $this->no_workflows();
      return;
    }

    $first = array_shift($workflows);
    $this->handle_workflow($first);

    foreach ($workflows as $wf) {
      $this->reschedule($wf, 0);
    }
  }

  /**
   * @return BehaviourWorkflow[]
   */
  abstract protected function get_workflows(ICommand $command): array;

  /**
   * Execute the behaviour for a single work item.
   *
   * $previous is the current step result (if any) so saga handlers can use history/phase.
   */
  abstract protected function execute_one(
    BaseBehaviourConfig $config,
    WorkItem $item,
    ?BehaviourExecutionResult $previous
  ): BehaviourExecutionResult;

  /**
   * Generate the work items for a workflow step.
   *
   * Implementations can:
   * - compute from workflow ref/meta (recommended)
   * - optionally include a payload for the ledger
   *
   * This must be deterministic for idempotency.
   */
  abstract protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList;

  /**
   * Schedule (or immediately run) the workflow to continue later.
   * Consumers decide the mechanism (ActionScheduler, outbox, cron, etc).
   */
  abstract protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void;

  protected function no_workflows(): void {}

  protected function handle_workflow(BehaviourWorkflow $workflow): void {
    $this->current_workflow = $workflow;
    $reschedule = false;

    // We never execute workflows without an ID: persist early so ledger items can attach to workflow_id.
    if ($workflow->get_id() === null) {
      $this->workflow_repo->save($workflow);
    }

    while (!$workflow->is_complete()) {
      $config = $workflow->get_current();
      $previous = $workflow->get_current_result();

      $result = $this->execute_step($workflow, $config, $previous);
      $complete = $workflow->maybe_advance($result);

      if ($result->status === BehaviourExecutionStatus::waiting) {
        break;
      }

      if (!$complete && $this->needs_rescheduling($result)) {
        $reschedule = true;
        break;
      }

      if ($result->status === BehaviourExecutionStatus::failed) {
        $workflow->fail();
        break;
      }
    }

    $this->workflow_repo->save($workflow);

    if ($reschedule) {
      $this->reschedule($workflow, static::$reschedule_interval);
    }
  }

  protected function needs_rescheduling(BehaviourExecutionResult $result): bool {
    if ($this->resources_exceeded()) return true;

    if (
      $result->status === BehaviourExecutionStatus::failed
      && static::$max_retries > $result->get_count_retries()
    ) {
      return true;
    }

    return false;
  }

  protected function execute_step(
    BehaviourWorkflow $workflow,
    BaseBehaviourConfig $config,
    ?BehaviourExecutionResult $previous
  ): BehaviourExecutionResult {
    $phase = $workflow->get_current_phase();
    $items = $this->ensure_work_items($workflow, $config);
    return $this->execute_with_ledger($workflow, $config, $previous, $items);
  }

  /**
   * Ensure ledger work items exist for this workflow step.
   */
  protected function ensure_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    $idx = $workflow->get_current_idx();
    $phase = $workflow->get_current_phase();
    $workflow_id = $workflow->get_id();

    $existing = $this->item_repo->get_for_step($workflow_id, $idx, $phase);
    if (!$existing->empty()) {
      return $existing;
    }

    $generated = $this->generate_work_items($workflow, $config);

    foreach ($generated as $item) {
      // Ensure step identity fields are set consistently.
      $item->workflow_id = $workflow_id;
      $item->behaviour_idx = $idx;
      $item->phase = $phase;
      $item->blog_id = is_multisite() ? get_current_blog_id() : 1;
      $this->item_repo->save($item);
    }

    return $this->item_repo->get_for_step($workflow_id, $idx, $phase);
  }

  protected function execute_with_ledger(
    BehaviourWorkflow $workflow,
    BaseBehaviourConfig $config,
    ?BehaviourExecutionResult $previous,
    WorkItemList $items
  ): BehaviourExecutionResult {
    $phase = $workflow->get_current_phase();
    $batch_size = $config instanceof BatchableBehaviourConfig ? $config->get_default_batch_size() : 1;

    $builder = BehaviourExecutionResult::builder_batched($config, $phase);

    $pending = [];
    $waiting = [];
    $failed = [];

    foreach ($items as $item) {
      if ($item->status === WorkItemStatus::pending) $pending[] = $item;
      if ($item->status === WorkItemStatus::waiting) $waiting[] = $item;
      if ($item->status === WorkItemStatus::failed) $failed[] = $item;
    }

    // If any are waiting and no pending, the workflow should pause.
    if (empty($pending) && !empty($waiting)) {
      return $builder(true, 'Waiting on work items', BehaviourExecutionStatus::waiting);
    }

    // If we have failed items and no pending, fail the step.
    if (empty($pending) && !empty($failed)) {
      return $builder(false, 'Work items failed', BehaviourExecutionStatus::failed);
    }

    $chunk = array_slice($pending, 0, max(1, $batch_size));
    foreach ($chunk as $work_item) {
      $result = $this->execute_one($config, $work_item, $previous);

      $work_item->attempts++;
      $work_item->updated_at = null;

      $mapped = $this->status_from_result($result);
      $work_item->status = $mapped;

      if ($mapped === WorkItemStatus::failed) {
        $work_item->last_error = (string) ($result->context['message'] ?? 'failed');
      } else {
        $work_item->last_error = null;
      }

      $this->item_repo->save($work_item);

      if ($mapped === WorkItemStatus::waiting) {
        return $builder(true, 'Waiting on work item', BehaviourExecutionStatus::waiting);
      }

      if ($mapped === WorkItemStatus::failed) {
        return $builder(false, 'Work item failed', BehaviourExecutionStatus::failed);
      }

      if ($this->resources_exceeded()) {
        return $builder(true, 'Resource limits reached', BehaviourExecutionStatus::batched);
      }
    }

    // Recompute state after processing chunk.
    $items = $this->item_repo->get_for_step(
      $workflow->get_id(),
      $workflow->get_current_idx(),
      $phase
    );

    $has_pending = false;
    $has_waiting = false;
    $has_failed = false;

    foreach ($items as $item) {
      if ($item->status === WorkItemStatus::pending) $has_pending = true;
      if ($item->status === WorkItemStatus::waiting) $has_waiting = true;
      if ($item->status === WorkItemStatus::failed) $has_failed = true;
    }

    if ($has_failed && !$has_pending) {
      return $builder(false, 'Work items failed', BehaviourExecutionStatus::failed);
    }

    if ($has_waiting && !$has_pending) {
      return $builder(true, 'Waiting on work items', BehaviourExecutionStatus::waiting);
    }

    if ($has_pending) {
      return $builder(true, 'Work items remaining', BehaviourExecutionStatus::batched);
    }

    return $builder(true, 'Work items done', BehaviourExecutionStatus::completed);
  }

  protected function status_from_result(BehaviourExecutionResult $result): WorkItemStatus {
    return match ($result->status) {
      BehaviourExecutionStatus::completed => WorkItemStatus::done,
      BehaviourExecutionStatus::waiting => WorkItemStatus::waiting,
      BehaviourExecutionStatus::skipped,
      BehaviourExecutionStatus::cancelled,
      BehaviourExecutionStatus::preempted => WorkItemStatus::skipped,
      BehaviourExecutionStatus::failed => WorkItemStatus::failed,
      default => $result->success ? WorkItemStatus::done : WorkItemStatus::failed,
    };
  }
}


