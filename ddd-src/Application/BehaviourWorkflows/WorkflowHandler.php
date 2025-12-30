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
 * - Supports forking: failed items can be spun off into a child workflow.
 */
abstract class WorkflowHandler implements ICommandHandler {
  use RescheduleAware;

  public static int $max_retries = 3;
  public static int $reschedule_interval = 5;
  public static int $fork_delay_seconds = 30;

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
      $item->workflow_id = $workflow_id;
      $item->behaviour_idx = $idx;
      $item->phase = $phase;
      $item->blog_id = is_multisite() ? get_current_blog_id() : 1;
      $this->item_repo->save($item);
    }

    return $this->item_repo->get_for_step($workflow_id, $idx, $phase);
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // Core execution with ledger
  // ─────────────────────────────────────────────────────────────────────────────

  /** @var array<string> Audit: item keys that succeeded in current chunk */
  protected array $chunk_success = [];

  /** @var array<string> Audit: item keys that failed in current chunk */
  protected array $chunk_error = [];

  protected function execute_with_ledger(
    BehaviourWorkflow $workflow,
    BaseBehaviourConfig $config,
    ?BehaviourExecutionResult $previous,
    WorkItemList $items
  ): BehaviourExecutionResult {
    $phase = $workflow->get_current_phase();
    $batch_size = $this->get_batch_size($config);

    // Reset audit tracking
    $this->chunk_success = [];
    $this->chunk_error = [];

    // No pending work - skip to resolution
    if (!$items->has_pending()) {
      goto resolve;
    }

    // Process a chunk of pending items
    $stop_reason = $this->process_chunk(
      $items->pending()->take($batch_size),
      $config,
      $previous
    );

    // If processing was interrupted, return appropriate result
    if ($stop_reason !== null) {
      return $this->result_for_stop_reason($stop_reason, $config, $phase);
    }

    // Recompute state after processing
    $items = $this->item_repo->get_for_step(
      $workflow->get_id(),
      $workflow->get_current_idx(),
      $phase
    );

    resolve:
      return $this->resolve_step_state($workflow, $config, $items, $phase);
  }

  /**
   * Process a chunk of work items.
   *
   * Tracks success/error item keys for audit trail (stored in result history).
   *
   * @return string|null Stop reason ('waiting', 'failed', 'resources') or null if chunk completed
   */
  protected function process_chunk(
    WorkItemList $chunk,
    BaseBehaviourConfig $config,
    ?BehaviourExecutionResult $previous
  ): ?string {
    foreach ($chunk as $work_item) {
      $result = $this->execute_one($config, $work_item, $previous);
      $this->apply_result_to_item($work_item, $result);
      $this->item_repo->save($work_item);

      // Track for audit trail
      $this->track_item_result($work_item);

      if ($work_item->status === WorkItemStatus::waiting) return 'waiting';
      if ($work_item->status === WorkItemStatus::failed) return 'failed';
      if ($this->resources_exceeded()) return 'resources';
    }

    return null;
  }

  /**
   * Apply execution result to work item.
   */
  protected function apply_result_to_item(WorkItem $item, BehaviourExecutionResult $result): void {
    $item->attempts++;
    $item->updated_at = null;
    $item->status = $this->status_from_result($result);
    $item->last_error = $item->status === WorkItemStatus::failed
      ? (string) ($result->context['message'] ?? 'failed')
      : null;
  }

  /**
   * Track item result for audit trail (not for logic).
   */
  protected function track_item_result(WorkItem $item): void {
    match ($item->status) {
      WorkItemStatus::done,
      WorkItemStatus::skipped => $this->chunk_success[] = $item->item_key,
      WorkItemStatus::failed  => $this->chunk_error[] = $item->item_key,
      default => null,
    };
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // State resolution
  // ─────────────────────────────────────────────────────────────────────────────

  /**
   * Resolve final state based on work item statuses.
   *
   * Handles: pending (more work), waiting, failed (maybe fork), done/skipped (complete).
   */
  protected function resolve_step_state(
    BehaviourWorkflow $workflow,
    BaseBehaviourConfig $config,
    WorkItemList $items,
    int $phase
  ): BehaviourExecutionResult {
    $status = $items->aggregate_status();

    // Forking opportunity on failure (before delegating to generic handler)
    if ($status === WorkItemStatus::failed) {
      return $this->maybe_fork_or_fail($workflow, $config, $items, $phase);
    }

    return $this->result_from_item_status($status, $config, $phase);
  }

  /**
   * Either fork failed items into a child workflow, or fail the step.
   */
  protected function maybe_fork_or_fail(
    BehaviourWorkflow $workflow,
    BaseBehaviourConfig $config,
    WorkItemList $items,
    int $phase
  ): BehaviourExecutionResult {
    $failed_items = $items->failed();

    // Can only fork if: config supports it, workflow isn't already forked, and we have failed items
    if (
      $config instanceof BatchableBehaviourConfig
      && !$workflow->is_forked()
      && !$failed_items->empty()
    ) {
      $this->fork_workflow($workflow, $config, $failed_items);
      return $this->build_result($config, $phase, true, 'Forked failed items to child workflow', BehaviourExecutionStatus::forked);
    }

    // Already forked or can't fork - fail
    $message = $workflow->is_forked()
      ? 'Forked workflow failed, will rely on retry system'
      : 'Work items failed';

    return $this->build_result($config, $phase, false, $message, BehaviourExecutionStatus::failed);
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // Forking
  // ─────────────────────────────────────────────────────────────────────────────

  /**
   * Fork failed items into a new child workflow.
   *
   * The child workflow:
   * - Has root_workflow_id pointing to parent (lineage tracking)
   * - Gets its own retry budget
   *
   * Work items are TRANSFERRED (not duplicated):
   * - Same item IDs, re-parented to child workflow
   * - Reset to pending with fresh attempt counter
   * - Preserves item history/identity
   */
  protected function fork_workflow(
    BehaviourWorkflow $parent,
    BaseBehaviourConfig $config,
    WorkItemList $failed_items
  ): void {
    $forked = new BehaviourWorkflow(
      id: null,
      ref_id: $parent->get_ref_id(),
      ref_type: $parent->get_ref_type(),
      behaviour_configs: [$config],
      behaviour_results: [],
      meta: $parent->get_all_meta(),
      root_workflow_id: $parent->get_id()
    );

    $this->workflow_repo->save($forked);

    // Transfer failed items to child workflow
    $this->transfer_items_to_workflow($failed_items, $forked);

    $this->reschedule($forked, static::$fork_delay_seconds);
  }

  /**
   * Transfer work items to a new workflow.
   *
   * Resets items to a fresh state (pending, 0 attempts) while preserving identity.
   */
  protected function transfer_items_to_workflow(WorkItemList $items, BehaviourWorkflow $target): void {
    foreach ($items as $item) {
      $item->workflow_id = $target->get_id();
      $item->behaviour_idx = 0;
      $item->phase = 1;
      $item->status = WorkItemStatus::pending;
      $item->attempts = 0;
      $item->last_error = null;
      $item->updated_at = null;

      $this->item_repo->save($item);
    }
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // Result building (with audit data)
  // ─────────────────────────────────────────────────────────────────────────────

  /**
   * Build a result with audit trail (batch_success/batch_error from current chunk).
   */
  protected function build_result(
    BaseBehaviourConfig $config,
    int $phase,
    bool $success,
    string $message,
    BehaviourExecutionStatus $status
  ): BehaviourExecutionResult {
    return BehaviourExecutionResult::builder_batched($config, $phase)(
      $success,
      $message,
      $status,
      $this->chunk_success,
      $this->chunk_error
    );
  }

  protected function get_batch_size(BaseBehaviourConfig $config): int {
    return $config instanceof BatchableBehaviourConfig
      ? $config->get_default_batch_size()
      : 1;
  }

  protected function result_from_item_status(
    WorkItemStatus $status,
    BaseBehaviourConfig $config,
    int $phase
  ): BehaviourExecutionResult {
    return match ($status) {
      WorkItemStatus::pending => $this->build_result($config, $phase, true, 'Work items remaining', BehaviourExecutionStatus::batched),
      WorkItemStatus::waiting => $this->build_result($config, $phase, true, 'Waiting on work items', BehaviourExecutionStatus::waiting),
      WorkItemStatus::failed  => $this->build_result($config, $phase, false, 'Work items failed', BehaviourExecutionStatus::failed),
      WorkItemStatus::done,
      WorkItemStatus::skipped => $this->build_result($config, $phase, true, 'Work items done', BehaviourExecutionStatus::completed),
    };
  }

  protected function result_for_stop_reason(
    string $reason,
    BaseBehaviourConfig $config,
    int $phase
  ): BehaviourExecutionResult {
    return match ($reason) {
      'waiting'   => $this->build_result($config, $phase, true, 'Waiting on work item', BehaviourExecutionStatus::waiting),
      'failed'    => $this->build_result($config, $phase, false, 'Work item failed', BehaviourExecutionStatus::failed),
      'resources' => $this->build_result($config, $phase, true, 'Resource limits reached', BehaviourExecutionStatus::batched),
      default     => $this->build_result($config, $phase, false, "Unknown stop reason: $reason", BehaviourExecutionStatus::failed),
    };
  }

  protected function status_from_result(BehaviourExecutionResult $result): WorkItemStatus {
    return match ($result->status) {
      BehaviourExecutionStatus::completed => WorkItemStatus::done,
      BehaviourExecutionStatus::waiting   => WorkItemStatus::waiting,
      BehaviourExecutionStatus::skipped,
      BehaviourExecutionStatus::cancelled,
      BehaviourExecutionStatus::preempted => WorkItemStatus::skipped,
      BehaviourExecutionStatus::failed    => WorkItemStatus::failed,
      default => $result->success ? WorkItemStatus::done : WorkItemStatus::failed,
    };
  }
}


