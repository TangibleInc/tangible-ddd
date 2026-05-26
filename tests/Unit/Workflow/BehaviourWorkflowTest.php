<?php

namespace TangibleDDD\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Exceptions\BusinessConstraintException;
use TangibleDDD\Domain\Exceptions\WorkflowException;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Tests\Fakes\FakeBatchableConfig;
use TangibleDDD\Tests\Fakes\FakeRetryConfig;
use TangibleDDD\Tests\Fakes\FakeSagaConfig;
use TangibleDDD\Tests\Fakes\FakeStopConfig;

class BehaviourWorkflowTest extends TestCase {

  private function make_result(
    string $type,
    BehaviourExecutionStatus $status,
    bool $success = true,
    int $phase = 1,
  ): BehaviourExecutionResult {
    return new BehaviourExecutionResult(
      type: $type,
      success: $success,
      context: ['message' => 'test'],
      status: $status,
      timestamp: gmdate('c'),
      phase: $phase,
    );
  }

  public function test_new_workflow_initial_state(): void {
    $configs = [new FakeStopConfig(), new FakeRetryConfig()];
    $wf = new BehaviourWorkflow(
      id: null,
      ref_id: 1,
      ref_type: 'request',
      behaviour_configs: $configs,
    );

    $this->assertNull($wf->get_id());
    $this->assertSame(1, $wf->get_ref_id());
    $this->assertSame('request', $wf->get_ref_type());
    $this->assertSame(0, $wf->get_current_idx());
    $this->assertSame(1, $wf->get_current_phase());
    $this->assertTrue($wf->is_active());
    $this->assertFalse($wf->is_complete());
    $this->assertFalse($wf->is_failed());
    $this->assertFalse($wf->is_forked());
  }

  public function test_advance_through_simple_behaviours(): void {
    $configs = [new FakeStopConfig(), new FakeRetryConfig()];
    $wf = new BehaviourWorkflow(null, 1, 'request', $configs);

    // Complete first behaviour
    $result1 = $this->make_result('stop', BehaviourExecutionStatus::completed);
    $complete = $wf->maybe_advance($result1);
    $this->assertFalse($complete);
    $this->assertSame(1, $wf->get_current_idx());

    // Complete second behaviour
    $result2 = $this->make_result('retry', BehaviourExecutionStatus::completed);
    $complete = $wf->maybe_advance($result2);
    $this->assertTrue($complete);
    $this->assertTrue($wf->is_complete());
  }

  public function test_failed_result_does_not_advance(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::failed, false);
    $complete = $wf->maybe_advance($result);

    $this->assertFalse($complete);
    $this->assertSame(0, $wf->get_current_idx());
    $this->assertFalse($wf->is_complete());
  }

  public function test_waiting_does_not_advance(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig(), new FakeRetryConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::waiting);
    $wf->maybe_advance($result);

    $this->assertSame(0, $wf->get_current_idx());
  }

  public function test_batched_does_not_advance(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig(), new FakeRetryConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::batched);
    $wf->maybe_advance($result);

    $this->assertSame(0, $wf->get_current_idx());
  }

  public function test_saga_advances_phase_not_idx(): void {
    $saga = new FakeSagaConfig(phases: 3);
    $wf = new BehaviourWorkflow(null, 1, 'request', [$saga, new FakeStopConfig()]);

    // Phase 1 completes
    $result = $this->make_result('saga', BehaviourExecutionStatus::completed, phase: 1);
    $wf->maybe_advance($result);
    $this->assertSame(0, $wf->get_current_idx()); // still on saga
    $this->assertSame(2, $wf->get_current_phase());

    // Phase 2 completes
    $result = $this->make_result('saga', BehaviourExecutionStatus::completed, phase: 2);
    $wf->maybe_advance($result);
    $this->assertSame(0, $wf->get_current_idx()); // still on saga
    $this->assertSame(3, $wf->get_current_phase());

    // Phase 3 completes → saga done, move to next config
    $result = $this->make_result('saga', BehaviourExecutionStatus::completed, phase: 3);
    $wf->maybe_advance($result);
    $this->assertSame(1, $wf->get_current_idx()); // moved past saga
    $this->assertSame(1, $wf->get_current_phase()); // phase reset
  }

  public function test_saga_cancelled_completes_saga(): void {
    $saga = new FakeSagaConfig(phases: 3);
    $wf = new BehaviourWorkflow(null, 1, 'request', [$saga, new FakeStopConfig()]);

    // Cancel during phase 1
    $result = $this->make_result('saga', BehaviourExecutionStatus::cancelled);
    $wf->maybe_advance($result);

    // Should jump past the saga to next config
    $this->assertSame(1, $wf->get_current_idx());
    $this->assertSame(1, $wf->get_current_phase());
  }

  public function test_saga_waiting_does_not_advance_phase(): void {
    $saga = new FakeSagaConfig(phases: 2);
    $wf = new BehaviourWorkflow(null, 1, 'request', [$saga]);

    $result = $this->make_result('saga', BehaviourExecutionStatus::waiting);
    $wf->maybe_advance($result);

    $this->assertSame(0, $wf->get_current_idx());
    $this->assertSame(1, $wf->get_current_phase()); // no phase advance
  }

  public function test_fail_marks_workflow_failed(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);
    $this->assertTrue($wf->is_active());

    $wf->fail();

    $this->assertTrue($wf->is_failed());
    $this->assertFalse($wf->is_active());
  }

  public function test_meta_bag(): void {
    $wf = new BehaviourWorkflow(
      null, 1, 'request', [new FakeStopConfig()],
      meta: ['attempt_id' => 42, 'source' => 'api'],
    );

    $this->assertSame(42, $wf->get_meta('attempt_id'));
    $this->assertSame('api', $wf->get_meta('source'));
    $this->assertSame('default', $wf->get_meta('missing', 'default'));
    $this->assertSame(['attempt_id' => 42, 'source' => 'api'], $wf->get_all_meta());
  }

  public function test_forked_workflow_constraints(): void {
    // A forked workflow can only have one behaviour
    $wf = new BehaviourWorkflow(
      null, 1, 'request', [new FakeStopConfig()],
      root_workflow_id: 99,
    );

    $this->assertTrue($wf->is_forked());
    $this->assertSame(99, $wf->get_root_workflow_id());
  }

  public function test_forked_workflow_rejects_multiple_behaviours(): void {
    $this->expectException(BusinessConstraintException::class);
    $this->expectExceptionMessage('forked workflow cannot have more than one behaviour');

    new BehaviourWorkflow(
      null, 1, 'request', [new FakeStopConfig(), new FakeRetryConfig()],
      root_workflow_id: 99,
    );
  }

  public function test_advance_on_completed_workflow_throws(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::completed);
    $wf->maybe_advance($result);
    $this->assertTrue($wf->is_complete());

    $this->expectException(WorkflowException::class);
    $wf->maybe_advance($result);
  }

  public function test_get_current_on_completed_workflow_throws(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::completed);
    $wf->maybe_advance($result);

    $this->expectException(WorkflowException::class);
    $wf->get_current();
  }

  public function test_follow_up_chains_result_history(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);

    // First attempt (batched)
    $r1 = $this->make_result('stop', BehaviourExecutionStatus::batched);
    $wf->maybe_advance($r1);

    // Second attempt (still batched) — should chain via follow_up
    $r2 = $this->make_result('stop', BehaviourExecutionStatus::batched);
    $wf->maybe_advance($r2);

    $current = $wf->get_current_result();
    $this->assertNotNull($current);
    $this->assertCount(1, $current->history);
  }

  public function test_behaviour_results_populated(): void {
    $wf = new BehaviourWorkflow(null, 1, 'request', [new FakeStopConfig()]);

    $result = $this->make_result('stop', BehaviourExecutionStatus::completed);
    $wf->maybe_advance($result);

    $results = $wf->get_behaviour_results();
    $this->assertCount(1, $results);
    $this->assertSame(BehaviourExecutionStatus::completed, $results[0]->status);
  }

  public function test_hydration_from_constructor(): void {
    $result = $this->make_result('stop', BehaviourExecutionStatus::completed);
    $wf = new BehaviourWorkflow(
      id: 42,
      ref_id: 1,
      ref_type: 'request',
      behaviour_configs: [new FakeStopConfig(), new FakeRetryConfig()],
      behaviour_results: [$result],
      current_idx: 1,
      current_phase: 1,
      is_complete: false,
      is_failed: false,
    );

    $this->assertSame(42, $wf->get_id());
    $this->assertSame(1, $wf->get_current_idx());
    $this->assertInstanceOf(FakeRetryConfig::class, $wf->get_current());
    $this->assertCount(1, $wf->get_behaviour_results());
  }
}
