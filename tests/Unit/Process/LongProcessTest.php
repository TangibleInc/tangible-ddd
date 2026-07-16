<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakePayload;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

class LongProcessTest extends TestCase {

  private function make_steps(): ProcessSteps {
    return new ProcessSteps(
      steps: ['initialize', 'process_data', 'finalize'],
      compensations: [],
    );
  }

  public function test_initial_state(): void {
    $process = new FakeThreeStepProcess();

    $this->assertNull($process->get_id());
    $this->assertSame('pending', $process->status());
    $this->assertNull($process->payload());
    $this->assertNull($process->steps());
    $this->assertNull($process->waiting_for());
    $this->assertNull($process->match_criteria());
    $this->assertNull($process->last_error());
  }

  public function test_start_transitions_to_running(): void {
    $process = new FakeThreeStepProcess();
    $steps = $this->make_steps();

    $process->initialize_lifecycle('corr-123', $steps);

    $this->assertSame('running', $process->status());
    $this->assertSame('corr-123', $process->correlation_id());
    $this->assertSame($steps, $process->steps());
    $this->assertNotNull($process->created_at());
    $this->assertNotNull($process->updated_at());
  }

  public function test_advance_updates_state(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $payload = new FakePayload('step1_done', 1);
    $process->advance(status: 'running', payload: $payload);

    $this->assertSame('running', $process->status());
    $this->assertInstanceOf(FakePayload::class, $process->payload());
    $this->assertSame('step1_done', $process->payload()->data);
  }

  public function test_advance_with_suspension(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $process->advance(
      status: 'suspended',
      payload: new FakePayload('waiting', 1),
      waiting_for: 'SomeEvent',
      match_criteria: ['id' => 42],
    );

    $this->assertSame('suspended', $process->status());
    $this->assertSame('SomeEvent', $process->waiting_for());
    $this->assertSame(['id' => 42], $process->match_criteria());
  }

  public function test_advance_step_moves_cursor(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $this->assertSame('initialize', $process->current_step_name());
    $this->assertSame(0, $process->current_step_index());

    $process->advance_step();
    $this->assertSame('process_data', $process->current_step_name());
    $this->assertSame(1, $process->current_step_index());
  }

  public function test_advance_step_to_jumps_cursor(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $process->advance_step_to(2);
    $this->assertSame('finalize', $process->current_step_name());
    $this->assertSame(2, $process->current_step_index());
  }

  public function test_complete_sets_status(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $process->complete();

    $this->assertSame('completed', $process->status());
    $this->assertNotNull($process->updated_at());
  }

  public function test_fail_sets_status_and_error(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $process->fail('something broke');

    $this->assertSame('failed', $process->status());
    $this->assertSame('something broke', $process->last_error());
  }

  public function test_record_and_retrieve_checkpoint(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $cp = new FakePayload('checkpoint_data', 99);
    $process->record_checkpoint($cp);

    $restored = $process->checkpoint_for('initialize');
    $this->assertInstanceOf(FakePayload::class, $restored);
    $this->assertSame('checkpoint_data', $restored->data);
  }

  public function test_checkpoint_for_missing_step_returns_null(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $this->assertNull($process->checkpoint_for('nonexistent'));
  }

  public function test_compensation_lifecycle(): void {
    $process = new FakeThreeStepProcess();
    $steps = new ProcessSteps(
      steps: ['step_a', 'step_b'],
      compensations: ['step_a' => 'undo_a'],
    );
    $process->initialize_lifecycle('corr-1', $steps);

    // Advance past step_a
    $process->advance_step();

    // step_b fails
    $process->begin_compensation('step_b blew up');

    $this->assertTrue($process->is_compensating());
    $this->assertSame('step_b', $process->failed_step());
    $this->assertSame('step_b blew up', $process->failure_message());

    // Undo step_a
    $this->assertSame('step_a', $process->current_undo_step());
    $this->assertSame('undo_a', $process->compensation_for('step_a'));

    $process->advance_compensation();
    $this->assertTrue($process->is_compensation_complete());

    $process->finish_compensation();
    $this->assertFalse($process->is_compensating());
    $this->assertSame('failed', $process->status());
  }

  public function test_find_step_index(): void {
    $process = new FakeThreeStepProcess();
    $process->initialize_lifecycle('corr-1', $this->make_steps());

    $this->assertSame(0, $process->find_step_index('initialize'));
    $this->assertSame(1, $process->find_step_index('process_data'));
    $this->assertSame(2, $process->find_step_index('finalize'));
    $this->assertSame(0, $process->find_step_index('nonexistent'));
  }

  public function test_hydrate_restores_full_state(): void {
    $process = new FakeThreeStepProcess();
    $steps = $this->make_steps();
    $payload = new FakePayload('hydrated', 5);
    $created = new \DateTimeImmutable('2025-01-01');
    $updated = new \DateTimeImmutable('2025-01-02');

    $process->hydrate(
      id: 42,
      status: 'suspended',
      correlation_id: 'corr-hydrated',
      steps: $steps,
      payload: $payload,
      waiting_for: 'SomeEvent',
      match_criteria: ['id' => 99],
      last_error: null,
      created_at: $created,
      updated_at: $updated,
    );

    $this->assertSame(42, $process->get_id());
    $this->assertSame('suspended', $process->status());
    $this->assertSame('corr-hydrated', $process->correlation_id());
    $this->assertSame($steps, $process->steps());
    $this->assertInstanceOf(FakePayload::class, $process->payload());
    $this->assertSame('hydrated', $process->payload()->data);
    $this->assertSame('SomeEvent', $process->waiting_for());
    $this->assertSame(['id' => 99], $process->match_criteria());
    $this->assertSame($created, $process->created_at());
    $this->assertSame($updated, $process->updated_at());
  }

  public function test_is_steps_complete(): void {
    $process = new FakeThreeStepProcess();
    $steps = new ProcessSteps(steps: ['a'], compensations: []);
    $process->initialize_lifecycle('corr-1', $steps);

    $this->assertFalse($process->is_steps_complete());

    $process->advance_step();
    $this->assertTrue($process->is_steps_complete());
  }

  public function test_domain_events_on_aggregate(): void {
    $process = new FakeThreeStepProcess();

    $event = new FakeDomainEvent(1);
    $process->event($event);
    $process->event(new FakeDomainEvent(2));

    $pulled = $process->pull_events();
    $this->assertCount(2, $pulled);
    $this->assertSame($event, $pulled[0]);

    // Second pull should be empty
    $this->assertEmpty($process->pull_events());
  }
}
