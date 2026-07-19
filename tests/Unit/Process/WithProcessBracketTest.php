<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;

/**
 * The sealed bracket: every saga wake — start, continuation, resume,
 * timeout — runs inside with_process(): lock, correlation scope, process
 * frame. The frame is what makes command-inside-process-scope legible to
 * the guards; the scope is worker hygiene.
 */
class WithProcessBracketTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    CorrelationContext::reset();
    CorrelationContext::init('bracket-corr');
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    Correlation::reset();
    CorrelationContext::reset();
  }

  private function probe(): LongProcess {
    return new class extends LongProcess {
      public ?string $frame_during_step = null;
      public ?string $correlation_during_step = null;

      public function __construct() { parent::__construct(null); }

      protected function observe(): Result {
        $cause = Correlation::current()->cause;
        $this->frame_during_step = $cause?->kind === \TangibleDDD\Application\Correlation\Kind::Trajectory ? $cause->id : null;
        $this->correlation_during_step = CorrelationContext::peek();
        return new Result();
      }
    };
  }

  public function test_steps_run_inside_the_process_frame(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
    $probe = $this->probe();

    $runner->start($probe);

    $this->assertSame((string) $probe->get_id(), $probe->frame_during_step);
    $this->assertSame('bracket-corr', $probe->correlation_during_step);
    $this->assertNull(Correlation::peek(), 'scope must close when the wake ends');
  }

  public function test_continuation_runs_inside_the_process_frame(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());
    $probe = $this->probe();

    $steps = \TangibleDDD\Application\Process\ProcessSteps::from_reflection(
      [new \ReflectionMethod($probe, 'observe')], []
    );
    $probe->initialize_lifecycle('bracket-corr', $steps);
    $probe->advance(status: 'scheduled', payload: null);
    $repo->save($probe);

    Correlation::reset();
    CorrelationContext::reset(); // fresh AS worker
    $runner->continue_scheduled($probe->get_id());

    $this->assertSame((string) $probe->get_id(), $probe->frame_during_step);
    $this->assertNull(Correlation::peek(), 'scope closed after the continuation');
  }
}
