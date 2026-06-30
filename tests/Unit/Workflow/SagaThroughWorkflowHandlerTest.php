<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Tests\Fakes\FakeBehaviourWorkflowRepository;
use TangibleDDD\Tests\Fakes\FakeSagaConfig;
use TangibleDDD\Tests\Fakes\FakeStopConfig;
use TangibleDDD\Tests\Fakes\FakeWorkItemRepository;

/**
 * DE-RISK: ISagaBehaviour end-to-end through WorkflowHandler.
 *
 * Purpose: go/no-go gate for cred's multi-phase saga migration.
 *
 * Uses FakeSagaConfig (ISagaBehaviour, configurable phases),
 * in-memory repos, and a concrete WorkflowHandler subclass whose
 * execute_one() switches on $item->phase to emulate a real cred-style saga.
 *
 * Assertion map:
 *   1. Phase advance — saga flows phase 1 → 2 → complete_saga()
 *   2. waiting + re-entry — second handle_workflow() re-finds same items, no duplicate
 *   3. waiting reschedule — engine does NOT auto-reschedule on waiting
 *   4. preempted — maps to skipped/completed, advances phase normally
 *   5. cancelled — what reaches maybe_advance() after ledger resolution
 */
class SagaThroughWorkflowHandlerTest extends TestCase
{
    private FakeBehaviourWorkflowRepository $wf_repo;
    private FakeWorkItemRepository          $item_repo;

    protected function setUp(): void
    {
        $this->wf_repo   = new FakeBehaviourWorkflowRepository();
        $this->item_repo = new FakeWorkItemRepository();
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function make_command(): ICommand
    {
        return new class implements ICommand {
            public function send(): mixed { return null; }
        };
    }

    /**
     * Build a handler where execute_one() returns a fixed result indexed by
     * ($behaviour_idx, $phase) from a map:
     *   [ 'idx:phase' => BehaviourExecutionStatus ]
     *
     * Any phase not in the map defaults to completed.
     * $rescheduled is filled by reference on every reschedule() call.
     *
     * @param array<string,BehaviourExecutionStatus> $phase_results
     * @param array $rescheduled
     */
    private function make_saga_handler(
        array $phase_results,
        array &$rescheduled
    ): WorkflowHandler {
        return new class(
            $this->wf_repo,
            $this->item_repo,
            $phase_results,
            $rescheduled,
        ) extends WorkflowHandler {

            public function __construct(
                $wf_repo,
                $item_repo,
                private readonly array $phase_results,
                private array          &$rescheduled,
            ) {
                parent::__construct($wf_repo, $item_repo);
            }

            // Expose handle_workflow() without going through handle() to keep
            // tests focused on single-workflow execution.
            public function run(BehaviourWorkflow $workflow): void
            {
                $this->handle_workflow($workflow);
            }

            protected function get_workflows(ICommand $command): array
            {
                return [];
            }

            /**
             * Generate a single work item keyed by "item-{idx}-{phase}".
             * This key is deterministic so re-entry always matches the same item.
             */
            protected function generate_work_items(
                BehaviourWorkflow   $workflow,
                BaseBehaviourConfig $config
            ): WorkItemList {
                $idx   = $workflow->get_current_idx();
                $phase = $workflow->get_current_phase();
                return new WorkItemList([
                    new WorkItem(
                        id:            null,
                        workflow_id:   0,       // set by ensure_work_items()
                        behaviour_idx: $idx,
                        phase:         $phase,
                        item_key:      "item-{$idx}-{$phase}",
                    ),
                ]);
            }

            protected function execute_one(
                BaseBehaviourConfig       $config,
                WorkItem                  $item,
                ?BehaviourExecutionResult $previous
            ): BehaviourExecutionResult {
                $key    = "{$item->behaviour_idx}:{$item->phase}";
                $status = $this->phase_results[$key] ?? BehaviourExecutionStatus::completed;

                return new BehaviourExecutionResult(
                    type:      $config->get_behaviour_type(),
                    success:   $status !== BehaviourExecutionStatus::failed,
                    context:   ['message' => "phase {$item->phase} → {$status->value}"],
                    status:    $status,
                    timestamp: gmdate('c'),
                    phase:     $item->phase,
                );
            }

            protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
            {
                $this->rescheduled[] = [$workflow->get_id(), $delay_seconds];
            }
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Assertion 1 — Phase advance: saga flows phase 1 → 2 → 3 → complete_saga()
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A 3-phase saga followed by a non-saga step completes both in one run.
     *
     * Checks:
     *  - current_phase steps 1 → 2 → 3 → (complete_saga → current_idx++)
     *  - current_idx reaches 2 (= past non-saga step) and is_complete() is true
     *  - exactly 3 work items created (one per phase, each at a different (idx, phase))
     *  - the loop does NOT stall on phase 1
     */
    public function test_saga_phases_advance_to_completion(): void
    {
        $saga = new FakeSagaConfig(phases: 3);
        $stop = new FakeStopConfig();

        $wf = new BehaviourWorkflow(
            id:                null,
            ref_id:            1,
            ref_type:          'saga_test',
            behaviour_configs: [$saga, $stop],
        );

        $rescheduled = [];
        $handler     = $this->make_saga_handler(
            phase_results: [],  // all phases → completed by default
            rescheduled:   $rescheduled
        );

        $handler->run($wf);

        // Saga must not stall: all 3 phases advanced, then idx++
        $this->assertTrue($wf->is_complete(), 'Workflow must be complete after all phases');
        $this->assertFalse($wf->is_failed(), 'Workflow must not be failed');

        // current_idx == 2 means we moved past both behaviours
        $this->assertSame(2, $wf->get_current_idx(), 'current_idx must be 2 after both behaviours');

        // 3 saga items (one per phase) + 1 non-saga item = 4 total saved items
        // ensure_work_items generates per (idx, phase), so 3 saga + 1 stop = 4 total
        $saga_item_p1 = $this->item_repo->get_for_step($wf->get_id(), 0, 1);
        $saga_item_p2 = $this->item_repo->get_for_step($wf->get_id(), 0, 2);
        $saga_item_p3 = $this->item_repo->get_for_step($wf->get_id(), 0, 3);
        $stop_item    = $this->item_repo->get_for_step($wf->get_id(), 1, 1);

        $this->assertSame(1, count($saga_item_p1), 'One item at (idx=0, phase=1)');
        $this->assertSame(1, count($saga_item_p2), 'One item at (idx=0, phase=2)');
        $this->assertSame(1, count($saga_item_p3), 'One item at (idx=0, phase=3)');
        $this->assertSame(1, count($stop_item),    'One item at (idx=1, phase=1)');

        // All saga items should be done
        $this->assertSame(WorkItemStatus::done, $saga_item_p1[0]->status, 'Phase-1 item must be done');
        $this->assertSame(WorkItemStatus::done, $saga_item_p2[0]->status, 'Phase-2 item must be done');
        $this->assertSame(WorkItemStatus::done, $saga_item_p3[0]->status, 'Phase-3 item must be done');

        // No reschedule on clean completion
        $this->assertEmpty($rescheduled, 'No reschedule expected on clean saga completion');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Assertion 2 — waiting + re-entry: same items found, no duplicate generated
    // Assertion 3 — waiting reschedule: engine does NOT auto-reschedule on waiting
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Run 1: phase 1 returns waiting → engine pauses.
     * Run 2: same (idx=0, phase=1) items are re-found (not re-generated).
     *        Phase 1 now returns completed → saga advances to phase 2 → completes.
     *
     * Also verifies: no auto-reschedule occurs after the waiting pause.
     */
    public function test_waiting_pauses_then_reruns_same_items(): void
    {
        $saga = new FakeSagaConfig(phases: 2);

        $wf = new BehaviourWorkflow(
            id:                null,
            ref_id:            2,
            ref_type:          'saga_test',
            behaviour_configs: [$saga],
        );

        // ── Run 1: phase 1 → waiting ──────────────────────────────────────────
        $rescheduled_run1 = [];
        $handler_run1 = $this->make_saga_handler(
            phase_results: ['0:1' => BehaviourExecutionStatus::waiting],
            rescheduled:   $rescheduled_run1
        );
        $handler_run1->run($wf);

        // Workflow is paused, not complete, not failed
        $this->assertFalse($wf->is_complete(), '[Run 1] Workflow must NOT be complete on waiting');
        $this->assertFalse($wf->is_failed(),   '[Run 1] Workflow must not be failed');

        // Assertion 3: no auto-reschedule on waiting
        $this->assertEmpty(
            $rescheduled_run1,
            '[A3] Engine must NOT auto-reschedule on waiting (consumer must self-reschedule)'
        );

        // Phase did not advance
        $this->assertSame(0, $wf->get_current_idx(),  '[Run 1] idx must stay 0');
        $this->assertSame(1, $wf->get_current_phase(), '[Run 1] phase must stay 1');

        // Capture item identity before Run 2
        $wf_id = $wf->get_id();
        $items_after_run1 = $this->item_repo->get_for_step($wf_id, 0, 1);
        $this->assertSame(1, count($items_after_run1), 'Exactly 1 item at (idx=0, phase=1) after Run 1');
        $item_id_run1 = $items_after_run1[0]->get_id();

        // Item status should be waiting after Run 1
        $this->assertSame(
            WorkItemStatus::waiting,
            $items_after_run1[0]->status,
            '[Run 1] Item status must be waiting'
        );

        $total_items_before_run2 = count($this->item_repo->store);

        // ── Run 2: phase 1 now → completed ───────────────────────────────────
        // We simulate an external signal resolved: same workflow object, but now
        // the item is waiting (was set in Run 1). The handler must re-find it.
        //
        // IMPORTANT: The waiting item is NOT pending, so ensure_work_items will
        // call get_for_step and find the existing item (not re-generate).
        // However, has_pending() will be false → goes to resolve: label →
        // aggregate_status() = waiting (item still has waiting status in store).
        // This means Run 2 will also pause UNLESS the item status is reset to pending
        // by the consumer before the re-run. This is the re-entry mechanism we test.
        //
        // We reset the item to pending to simulate the external signal being received.
        $items_after_run1[0]->status = WorkItemStatus::pending;
        $this->item_repo->save($items_after_run1[0]);

        $rescheduled_run2 = [];
        $handler_run2 = $this->make_saga_handler(
            phase_results: [],  // both phases → completed
            rescheduled:   $rescheduled_run2
        );
        $handler_run2->run($wf);

        // Assertion 2a: no duplicate items generated
        $total_items_after_run2 = count($this->item_repo->store);
        $items_at_p1_run2 = $this->item_repo->get_for_step($wf_id, 0, 1);
        $this->assertSame(1, count($items_at_p1_run2), '[A2] Must be exactly 1 item at (idx=0, phase=1) — no duplicate');
        $this->assertSame(
            $item_id_run1,
            $items_at_p1_run2[0]->get_id(),
            '[A2] Must be the SAME item identity — re-found, not re-generated'
        );

        // Assertion 2b: item count in store must not have grown at (idx=0, phase=1)
        // (total may grow because phase 2 items are new)
        $this->assertSame(
            WorkItemStatus::done,
            $items_at_p1_run2[0]->status,
            '[Run 2] Phase-1 item must now be done'
        );

        // Assertion 2c: saga completed after Run 2
        $this->assertTrue($wf->is_complete(), '[Run 2] Workflow must complete after all phases done');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Assertion 4 — preempted: maps to skipped/completed, phase advances normally
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Phase 1 returns preempted → item becomes skipped → step resolves as completed
     * → maybe_advance increments current_phase (not complete_saga).
     * Phase 2 returns completed → saga finishes.
     */
    public function test_preempted_advances_phase_normally(): void
    {
        $saga = new FakeSagaConfig(phases: 2);

        $wf = new BehaviourWorkflow(
            id:                null,
            ref_id:            3,
            ref_type:          'saga_test',
            behaviour_configs: [$saga],
        );

        $rescheduled = [];
        $handler = $this->make_saga_handler(
            phase_results: ['0:1' => BehaviourExecutionStatus::preempted],
            rescheduled:   $rescheduled
        );

        $handler->run($wf);

        // Phase-1 item should be skipped
        $p1_items = $this->item_repo->get_for_step($wf->get_id(), 0, 1);
        $this->assertSame(1, count($p1_items), 'One item at phase 1');
        $this->assertSame(
            WorkItemStatus::skipped,
            $p1_items[0]->status,
            '[A4] preempted → WorkItemStatus::skipped'
        );

        // Phase-2 item should be done
        $p2_items = $this->item_repo->get_for_step($wf->get_id(), 0, 2);
        $this->assertSame(1, count($p2_items), 'One item at phase 2');
        $this->assertSame(
            WorkItemStatus::done,
            $p2_items[0]->status,
            '[A4] Phase-2 item must be done'
        );

        // Saga must be complete
        $this->assertTrue($wf->is_complete(), '[A4] Workflow must complete after preempted+completed');
        $this->assertFalse($wf->is_failed(),  '[A4] Workflow must not be failed');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Assertion 5 — cancelled: what does the ledger actually deliver to maybe_advance?
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A 3-phase saga where phase 1 returns cancelled from execute_one().
     *
     * The question: does the engine deliver BehaviourExecutionStatus::cancelled
     * to maybe_advance() (which would call complete_saga() and skip phases 2+3),
     * or does the ledger convert it to completed (which just advances the phase)?
     *
     * Ledger path:
     *   execute_one() → cancelled
     *   apply_result_to_item() → item.status = skipped  (status_from_result maps cancelled → skipped)
     *   aggregate_status() → done (all items skipped = done)
     *   result_from_item_status(done) → BehaviourExecutionStatus::completed
     *   maybe_advance(completed on saga) → increments current_phase (NOT complete_saga())
     *
     * EXPECTED RED: the 'cancelled' intent is lost through the ledger; phases 2 and 3
     * are executed instead of being skipped. This is a framework gap.
     *
     * We assert both what actually happens AND what we would need for correct semantics.
     */
    public function test_cancelled_from_execute_one_through_ledger(): void
    {
        $saga = new FakeSagaConfig(phases: 3);

        $wf = new BehaviourWorkflow(
            id:                null,
            ref_id:            4,
            ref_type:          'saga_test',
            behaviour_configs: [$saga],
        );

        // Track which phases were actually executed
        $phases_executed = [];

        $rescheduled = [];
        $handler = new class(
            $this->wf_repo,
            $this->item_repo,
            $phases_executed,
            $rescheduled,
        ) extends WorkflowHandler {
            public function __construct(
                $wf_repo,
                $item_repo,
                private array &$phases_executed,
                private array &$rescheduled,
            ) {
                parent::__construct($wf_repo, $item_repo);
            }

            public function run(BehaviourWorkflow $workflow): void
            {
                $this->handle_workflow($workflow);
            }

            protected function get_workflows(ICommand $command): array { return []; }

            protected function generate_work_items(
                BehaviourWorkflow $workflow, BaseBehaviourConfig $config
            ): WorkItemList {
                $idx   = $workflow->get_current_idx();
                $phase = $workflow->get_current_phase();
                return new WorkItemList([
                    new WorkItem(null, 0, $idx, $phase, "item-{$idx}-{$phase}"),
                ]);
            }

            protected function execute_one(
                BaseBehaviourConfig       $config,
                WorkItem                  $item,
                ?BehaviourExecutionResult $previous
            ): BehaviourExecutionResult {
                $this->phases_executed[] = $item->phase;

                // Phase 1 returns cancelled; phases 2+3 return completed
                $status = ($item->phase === 1)
                    ? BehaviourExecutionStatus::cancelled
                    : BehaviourExecutionStatus::completed;

                return new BehaviourExecutionResult(
                    type:      $config->get_behaviour_type(),
                    success:   true,
                    context:   ['message' => "phase {$item->phase} → {$status->value}"],
                    status:    $status,
                    timestamp: gmdate('c'),
                    phase:     $item->phase,
                );
            }

            protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
            {
                $this->rescheduled[] = $workflow->get_id();
            }
        };

        $handler->run($wf);

        // ── Document the actual behaviour ─────────────────────────────────────

        $wf_id = $wf->get_id();
        $p1 = $this->item_repo->get_for_step($wf_id, 0, 1);
        $p2 = $this->item_repo->get_for_step($wf_id, 0, 2);
        $p3 = $this->item_repo->get_for_step($wf_id, 0, 3);

        // Phase-1 item: execute_one returned cancelled → apply_result_to_item maps to skipped
        $this->assertSame(
            WorkItemStatus::skipped,
            $p1[0]->status,
            '[A5] Phase-1 item: cancelled from execute_one → WorkItemStatus::skipped in ledger'
        );

        // ── The pivotal question: were phases 2 and 3 executed? ───────────────
        //
        // CORRECT behaviour (if cancelled worked as a "strong skip" through the ledger):
        //   $phases_executed === [1]  (phases 2+3 skipped; maybe_advance called complete_saga)
        //
        // ACTUAL behaviour (ledger washes cancelled → completed → phase just increments):
        //   $phases_executed === [1, 2, 3]  (all phases ran; cancelled intent was lost)

        $cancelled_skipped_phases_2_and_3 = !in_array(2, $phases_executed, true)
                                         && !in_array(3, $phases_executed, true);

        if ($cancelled_skipped_phases_2_and_3) {
            // GREEN sub-path: cancelled propagated correctly through the ledger
            $this->assertSame(
                [1],
                $phases_executed,
                '[A5 GREEN] cancelled correctly skipped phases 2+3'
            );
            // Workflow should be complete (complete_saga was called)
            $this->assertTrue(
                $wf->is_complete(),
                '[A5 GREEN] Workflow must be complete after saga cancelled'
            );
        } else {
            // RED sub-path: cancelled was washed to completed by the ledger;
            // phases 2+3 were executed even though phase 1 cancelled.
            $this->assertSame(
                [1, 2, 3],
                $phases_executed,
                '[A5 RED] FRAMEWORK GAP: cancelled from execute_one is lost; phases 2+3 executed'
            );

            // The workflow is still complete (phases advanced normally), but for the
            // wrong reason: it ran all phases instead of skipping after cancel.
            $this->assertTrue(
                $wf->is_complete(),
                '[A5 RED] Workflow completed (but ran all phases — cancelled was ignored)'
            );

            // Explicitly fail to mark this as RED — the framework gap is confirmed.
            $this->fail(
                '[A5] FRAMEWORK GAP CONFIRMED: execute_one returning cancelled is converted to ' .
                'WorkItemStatus::skipped by apply_result_to_item(), then aggregate_status() resolves ' .
                'to done, and result_from_item_status(done) returns BehaviourExecutionStatus::completed. ' .
                'maybe_advance(completed) on a saga increments current_phase instead of calling ' .
                'complete_saga(). Phases executed: [' . implode(', ', $phases_executed) . ']. ' .
                'MINIMAL FIX: WorkflowHandler::resolve_step_state() (or result_from_item_status()) ' .
                'must detect that ALL items are skipped via a "cancelled" result and return ' .
                'BehaviourExecutionStatus::cancelled instead of completed. One approach: track the ' .
                'highest-priority per-item execution status alongside the ledger status, or add a ' .
                'WorkItemStatus::cancelled and map execute_one(cancelled) to it. Then aggregate_status() ' .
                'can return cancelled, and result_from_item_status(cancelled) returns ' .
                'BehaviourExecutionStatus::cancelled for correct saga abort semantics.'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Assertion 5b — cancelled mid-saga: ledger integrity (no broken half-advance)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Even though cancelled is washed to completed through the ledger (documented above),
     * the workflow must not end up in a broken/inconsistent ledger state.
     *
     * Specifically: after a "cancelled" phase, the items and workflow state must be
     * internally consistent — no orphaned pending items at the old phase, no
     * current_idx pointing beyond behaviour_configs bounds.
     *
     * This test verifies that whatever path the engine takes, the ledger remains clean.
     */
    public function test_cancelled_mid_saga_leaves_clean_ledger(): void
    {
        // 2-phase saga. Phase 1 cancels, phase 2 completes (or doesn't run).
        $saga = new FakeSagaConfig(phases: 2);
        $stop = new FakeStopConfig();

        $wf = new BehaviourWorkflow(
            id:                null,
            ref_id:            5,
            ref_type:          'saga_test',
            behaviour_configs: [$saga, $stop],
        );

        $rescheduled = [];
        $handler = $this->make_saga_handler(
            phase_results: ['0:1' => BehaviourExecutionStatus::cancelled],
            rescheduled:   $rescheduled
        );

        $handler->run($wf);

        // Workflow must not be failed (cancelled is not a failure)
        $this->assertFalse($wf->is_failed(), '[A5b] Workflow must not be failed after cancelled');

        // Workflow must be complete (whether via complete_saga or phase-advance-then-stop-completes)
        $this->assertTrue($wf->is_complete(), '[A5b] Workflow must eventually complete, not hang');

        // current_idx must not be out of bounds (0 = saga, 1 = stop, 2 = done)
        $this->assertGreaterThanOrEqual(0, $wf->get_current_idx(), '[A5b] current_idx must be >= 0');
        $this->assertLessThanOrEqual(2, $wf->get_current_idx(), '[A5b] current_idx must not exceed config count');

        // No items should be stuck in pending state
        $wf_id          = $wf->get_id();
        $all_item_keys  = array_keys($this->item_repo->store);
        $stuck_pending  = array_filter(
            $this->item_repo->store,
            fn(WorkItem $i) => $i->workflow_id === $wf_id && $i->status === WorkItemStatus::pending
        );
        $this->assertEmpty(
            $stuck_pending,
            '[A5b] No items must be stuck in pending state after workflow completes'
        );
    }
}
