<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;

/**
 * The wake bracket on the facade (0.3 lane 3): with_process() = lock +
 * ONE Correlation::within($ctx->for_trajectory(...)) — the story and the
 * cause travel in one value. dispatch_commands()'s per-command arming loop
 * is gone: commands dispatched by a step parent on the trajectory by BEING
 * INSIDE the scope. Legacy statics stay dual-written transitionally.
 */
class WakeBracketTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    CorrelationContext::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    Correlation::reset();
    CorrelationContext::reset();
  }

  private function probe(): LongProcess {
    return new class extends LongProcess {
      public ?TraceContext $facade_during_step = null;
      public ?string $legacy_frame = null;
      public ?string $legacy_corr = null;

      public function __construct() { parent::__construct(null); }

      protected function observe(): Result {
        $this->facade_during_step = Correlation::current();
        $this->legacy_frame = CorrelationContext::process_frame();
        $this->legacy_corr = CorrelationContext::peek();
        return new Result();
      }
    };
  }

  public function test_steps_run_inside_a_trajectory_scope(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
    $probe = $this->probe();

    Correlation::within(new TraceContext('wake-corr'), static function () use ($runner, $probe) {
      $runner->start($probe);
    });

    $ctx = $probe->facade_during_step;
    $this->assertSame(Kind::Trajectory, $ctx->cause->kind);
    $this->assertSame((string) $probe->get_id(), $ctx->cause->id);
    $this->assertSame(get_class($probe), $ctx->cause->label);
    $this->assertSame('wake-corr', $ctx->correlation_id, 'the saga inherits the igniting story');

    $this->assertSame((string) $probe->get_id(), $probe->legacy_frame, 'legacy dual-write intact');
    $this->assertSame('wake-corr', $probe->legacy_corr);
  }

  public function test_dispatched_commands_are_inside_the_trajectory_scope(): void {
    $seen = new \ArrayObject();
    $process = new class($seen) extends LongProcess {
      public function __construct(private \ArrayObject $seen) { parent::__construct(null); }

      protected function act(): Result {
        $seen = $this->seen;
        $command = new class($seen) implements ICommand {
          public function __construct(private \ArrayObject $seen) {}
          public function send(): mixed {
            $this->seen[] = Correlation::current()->cause;
            return null;
          }
        };
        return new Result(commands: [$command]);
      }
    };

    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
    $runner->start($process);

    $this->assertCount(1, $seen);
    $this->assertSame(Kind::Trajectory, $seen[0]->kind, 'no arming loop — scope semantics parent the command');
    $this->assertSame((string) $process->get_id(), $seen[0]->id);
  }

  public function test_cross_story_wake_switches_and_restores(): void {
    // Story A's saga woken while story B's drain is ambient: the bracket
    // flips to A for the wake, restores B on exit — both worlds.
    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());
    $probe = $this->probe();

    Correlation::within(new TraceContext('story-a'), static function () use ($runner, $probe) {
      $runner->start($probe);   // saga born in story A
    });

    $this->assertSame('story-a', $probe->facade_during_step->correlation_id);
    $this->assertNull(Correlation::peek(), 'outer restored');
  }

  public function test_start_guards_read_the_facade(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());

    try {
      Correlation::within(Correlation::current()->for_act('cmd-1', 'Acme\\SomeCommand'), function () use ($runner) {
        $runner->start($this->probe());
      });
      $this->fail('start inside an act scope must throw');
    } catch (\TangibleDDD\Application\Process\ProcessStartedInsideCommand $e) {
      $this->assertStringContainsString('Acme\\SomeCommand', $e->getMessage(), 'label names the enclosing act');
    }

    $this->expectException(\TangibleDDD\Application\Process\ProcessStartedInsideProcess::class);
    Correlation::within(Correlation::current()->for_trajectory('191'), function () use ($runner) {
      $runner->start($this->probe());
    });
  }

  public function test_absorb_reads_the_facade_fact_scope(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
    $probe = $this->probe();

    Correlation::within(Correlation::current()->for_fact('evt-facade'), static function () use ($runner, $probe) {
      $runner->start($probe);
    });

    $this->assertSame('evt-facade', $probe->ignited_by_event_id());
    $this->assertSame('event', $probe->source());
  }
}
