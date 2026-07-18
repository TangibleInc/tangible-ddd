<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\ProcessStartedInsideCommand;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * start() is the edge door: legal from flat contexts (REST, CLI, WP hooks,
 * drain), forbidden inside a command pass — a handler wanting a saga records
 * an event and lets #[StartsOn] react. The guard reads the command frame.
 */
class ProcessStartGuardTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_start_inside_a_command_pass_throws(): void {
    CorrelationContext::mark_command_frame('Acme\\SomeCommand');

    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());

    try {
      $runner->start(new FakeThreeStepProcess());
      $this->fail('start() inside a command pass must throw');
    } catch (ProcessStartedInsideCommand $e) {
      $this->assertStringContainsString('Acme\\SomeCommand', $e->getMessage());
      $this->assertStringContainsString(FakeThreeStepProcess::class, $e->getMessage());
    }

    $this->assertSame([], $repo->processes, 'nothing may persist on a refused start');
  }

  public function test_start_from_a_flat_context_runs_in_band(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());
    $process = new FakeThreeStepProcess();

    $runner->start($process);

    $this->assertSame('completed', $process->status());
    $this->assertSame(['initialize', 'process_data', 'finalize'], $process->executed_steps);
  }

  public function test_start_inside_a_process_scope_is_legal(): void {
    // Future child-saga lane: a step spawning a process runs inside the
    // runner's bracket (process frame, no command frame) — must pass.
    CorrelationContext::mark_process_frame('99');

    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
    $process = new FakeThreeStepProcess();
    $runner->start($process);
    $this->assertSame('completed', $process->status());

    CorrelationContext::clear_process_frame();
  }
}
