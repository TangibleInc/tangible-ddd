<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * The absorb (0.2.5, facade-native since 0.4): a manual ->start() inside a
 * drain is a legal-but-dispreferred spelling of event ignition — the ambient
 * cause IS the fact that caused the start, and pre-absorb the process
 * recorded as a cold root (ignited_by null). start() reads the ambient fact:
 * record the truth, don't punish the spelling. #[StartsOn] remains the
 * better door (dedup + discovery), not the only honest one.
 *
 * Act/Trajectory ambients never reach the absorb — the start guards throw
 * first (ProcessStartGuardTest / ProcessStartInsideProcessTest).
 */
class ProcessStartAbsorbTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function make_runner(?FakeProcessRepository &$repo = null): ProcessRunner {
    return new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());
  }

  public function test_start_inside_a_drain_absorbs_the_event_as_igniter(): void {
    $process = new FakeThreeStepProcess();
    $runner = $this->make_runner();

    Correlation::within(Correlation::current()->for_fact('evt-manual'), static function () use ($runner, $process) {
      $runner->start($process);
    });

    $this->assertSame('evt-manual', $process->ignited_by_event_id(), 'the fact that caused the start is recorded');
    $this->assertSame('event', $process->source(), 'source reflects the event door, however spelled');
  }

  public function test_cold_start_stays_a_root(): void {
    $process = new FakeThreeStepProcess();
    $this->make_runner()->start($process);

    $this->assertNull($process->ignited_by_event_id());
    $this->assertNotSame('event', $process->source(), 'flat-context starts keep their cli/web source');
  }

  public function test_explicit_ignition_is_not_overwritten(): void {
    // The #[StartsOn] path marks BEFORE start(); the ambient fact must not clobber it.
    $process = new FakeThreeStepProcess();
    $process->mark_ignited_by('evt-explicit');
    $process->mark_source('event');
    $runner = $this->make_runner();

    Correlation::within(Correlation::current()->for_fact('evt-ambient'), static function () use ($runner, $process) {
      $runner->start($process);
    });

    $this->assertSame('evt-explicit', $process->ignited_by_event_id());
  }
}
