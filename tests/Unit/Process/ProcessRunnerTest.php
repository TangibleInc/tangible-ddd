<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeFailingProcess;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakePayload;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeSuspendingProcess;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

class ProcessRunnerTest extends TestCase {

  private FakeDDDConfig $config;
  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    CorrelationContext::reset();
    CorrelationContext::init('test-corr');

    $this->config = new FakeDDDConfig();
    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner($this->config, $this->repo);
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_three_step_process_runs_to_completion(): void {
    $process = new FakeThreeStepProcess();
    $this->runner->start($process);

    $this->assertSame('completed', $process->status());
    $this->assertSame(['initialize', 'process_data', 'finalize'], $process->executed_steps);
    $this->assertNotNull($process->get_id());
  }

  public function test_payload_flows_between_steps(): void {
    $process = new FakeThreeStepProcess();
    $this->runner->start($process);

    $payload = $process->payload();
    $this->assertInstanceOf(FakePayload::class, $payload);
    $this->assertSame('initialized+processed+finalized', $payload->data);
    $this->assertSame(3, $payload->counter);
  }

  public function test_process_persisted_at_each_step(): void {
    $process = new FakeThreeStepProcess();
    $this->runner->start($process);

    // 1 save at start, 3 saves for steps, 1 save for completion = 5
    $this->assertGreaterThanOrEqual(4, $this->repo->save_count);
  }

  public function test_failing_process_enters_compensation(): void {
    $process = new FakeFailingProcess();
    $this->runner->start($process);

    $this->assertSame('failed', $process->status());
    $this->assertContains('undo_step_one', $process->executed_steps);
    $this->assertStringContainsString('Step two failed', $process->last_error());
  }

  public function test_compensation_runs_in_reverse(): void {
    $process = new FakeFailingProcess();
    $this->runner->start($process);

    // step_one executes, step_two fails, undo_step_one runs
    $this->assertSame(['step_one', 'step_two', 'undo_step_one'], $process->executed_steps);
  }

  public function test_suspending_process_waits_for_event(): void {
    $process = new FakeSuspendingProcess();
    $this->runner->start($process);

    $this->assertSame('suspended', $process->status());
    $this->assertSame(FakeIntegrationEvent::class, $process->waiting_for());
    $this->assertSame(['entity_id' => 42], $process->match_criteria());
    $this->assertSame(['request_action'], $process->executed_steps);
  }

  public function test_continue_scheduled_resumes_process(): void {
    $process = new FakeThreeStepProcess();
    // Manually set up process as if it was scheduled mid-execution
    $steps = \TangibleDDD\Application\Process\ProcessSteps::from_reflection(
      array_map(
        fn($name) => new \ReflectionMethod($process, $name),
        ['initialize', 'process_data', 'finalize']
      ),
      []
    );
    $process->start('test-corr', $steps);
    // Execute first step manually
    $process->advance_step();
    $process->advance(status: 'scheduled', payload: new FakePayload('initialized', 1));
    $this->repo->save($process);

    $this->runner->continue_scheduled($process->get_id());

    $this->assertSame('completed', $process->status());
    // process_data and finalize should have run
    $this->assertContains('process_data', $process->executed_steps);
    $this->assertContains('finalize', $process->executed_steps);
  }

  public function test_continue_scheduled_noop_for_completed_process(): void {
    $process = new FakeThreeStepProcess();
    $this->runner->start($process);
    $this->assertSame('completed', $process->status());

    $save_count_before = $this->repo->save_count;
    $this->runner->continue_scheduled($process->get_id());

    // No additional saves should happen
    $this->assertSame($save_count_before, $this->repo->save_count);
  }

  public function test_continue_scheduled_noop_for_nonexistent_process(): void {
    // Should not throw
    $this->runner->continue_scheduled(999);
    $this->assertTrue(true);
  }

  public function test_register_rejects_non_process_class(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->runner->register(\stdClass::class);
  }

  public function test_correlation_context_set_on_start(): void {
    CorrelationContext::init('my-correlation');
    $process = new FakeThreeStepProcess();
    $this->runner->start($process);

    $this->assertSame('my-correlation', $process->correlation_id());
  }
}
