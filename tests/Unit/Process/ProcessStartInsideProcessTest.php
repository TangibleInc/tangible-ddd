<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\ProcessStartedInsideProcess;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * Trajectory→Trajectory is a forbidden cell (0.2.5): a step starting a
 * process directly would make the child's birth an un-audited side effect.
 * The legal spelling is parent step → Act (audited fan-out decision) → its
 * handler announces a Fact → #[StartsOn] ignites the child. Every hop
 * recorded, dedup and the human door included.
 */
class ProcessStartInsideProcessTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  public function test_start_inside_a_process_wake_throws(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());

    try {
      Correlation::within(Correlation::current()->for_trajectory('191'), static function () use ($runner) {
        $runner->start(new FakeThreeStepProcess());
      });
      $this->fail('start() inside a saga wake must throw');
    } catch (ProcessStartedInsideProcess $e) {
      $this->assertStringContainsString(FakeThreeStepProcess::class, $e->getMessage());
      $this->assertStringContainsString('191', $e->getMessage());
      $this->assertStringContainsString('StartsOn', $e->getMessage(), 'remediation names the legal spelling');
    }

    $this->assertSame([], $repo->processes, 'nothing may persist on a refused start');
  }
}
