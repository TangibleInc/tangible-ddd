<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Tests\Fakes\FakePayload;

class ProcessStepsTest extends TestCase {

  private function make_steps(array $methods = ['init', 'process', 'finalize'], array $compensations = []): ProcessSteps {
    return new ProcessSteps(
      steps: $methods,
      compensations: $compensations,
    );
  }

  public function test_initial_state(): void {
    $steps = $this->make_steps();

    $this->assertSame(0, $steps->step_index);
    $this->assertFalse($steps->is_complete());
    $this->assertFalse($steps->is_compensating());
    $this->assertSame('init', $steps->current_step());
  }

  public function test_advance_moves_cursor(): void {
    $steps = $this->make_steps();

    $steps->advance();
    $this->assertSame('process', $steps->current_step());

    $steps->advance();
    $this->assertSame('finalize', $steps->current_step());

    $steps->advance();
    $this->assertTrue($steps->is_complete());
    $this->assertNull($steps->current_step());
  }

  public function test_compensation_lifecycle(): void {
    $steps = $this->make_steps(
      ['step_a', 'step_b', 'step_c'],
      ['step_a' => 'undo_a', 'step_b' => 'undo_b']
    );

    // Advance through step_a and step_b
    $steps->advance();
    $steps->advance();
    // Now at step_c (index 2), about to execute

    // step_c fails — begin undo from last completed step (index 1)
    $steps->begin_undo('step_c blew up');
    $this->assertTrue($steps->is_compensating());
    $this->assertSame('step_c blew up', $steps->failure_msg);
    $this->assertSame('step_c', $steps->failed_step());

    // Undo starts at step_b (index 1)
    $this->assertSame('step_b', $steps->current_undo_step());
    $this->assertSame('undo_b', $steps->compensation_for('step_b'));

    $steps->advance_undo();
    $this->assertSame('step_a', $steps->current_undo_step());
    $this->assertSame('undo_a', $steps->compensation_for('step_a'));

    $steps->advance_undo();
    $this->assertTrue($steps->undo_index < 0);
  }

  public function test_compensation_for_returns_null_when_no_compensation(): void {
    $steps = $this->make_steps(['a', 'b'], []);

    $this->assertNull($steps->compensation_for('a'));
    $this->assertNull($steps->compensation_for('nonexistent'));
  }

  public function test_checkpoint_round_trip(): void {
    $steps = $this->make_steps();
    $checkpoint = new FakePayload('checkpoint_data', 42);

    $steps->record_checkpoint('init', $checkpoint);

    $restored = $steps->checkpoint_for('init');
    $this->assertInstanceOf(FakePayload::class, $restored);
    $this->assertSame('checkpoint_data', $restored->data);
    $this->assertSame(42, $restored->counter);
  }

  public function test_checkpoint_for_returns_null_when_missing(): void {
    $steps = $this->make_steps();
    $this->assertNull($steps->checkpoint_for('nonexistent'));
  }

  public function test_json_serialization_round_trip(): void {
    $steps = $this->make_steps(['a', 'b'], ['a' => 'undo_a']);
    $steps->advance();
    $steps->record_checkpoint('a', new FakePayload('cp', 1));

    $json = $steps->to_json(true);
    $restored = ProcessSteps::from_json($json);

    $this->assertSame(1, $restored->step_index);
    $this->assertSame(['a', 'b'], $restored->steps);
    $this->assertSame(['a' => 'undo_a'], $restored->compensations);
    $this->assertSame('b', $restored->current_step());
  }

  public function test_finish_undo_resets_undo_index(): void {
    $steps = $this->make_steps(['a', 'b'], ['a' => 'undo_a']);
    $steps->advance(); // complete step 'a', now at step_index 1
    $steps->begin_undo('error'); // undo_index = step_index - 1 = 0
    $this->assertTrue($steps->is_compensating());

    $steps->finish_undo();
    $this->assertFalse($steps->is_compensating());
  }
}
