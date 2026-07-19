<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * The absorb (0.2.5): a manual ->start() inside a drain is a legal-but-
 * dispreferred spelling of event ignition — a fact demonstrably caused the
 * start, the ambient causation slot is armed with it, and pre-absorb the
 * process recorded as a cold root (ignited_by null). start() now reads the
 * armed slot: record the truth, don't punish the spelling. #[StartsOn]
 * remains the better door (dedup + discovery), not the only honest one.
 */
class ProcessStartAbsorbTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  private function make_runner(?FakeProcessRepository &$repo = null): ProcessRunner {
    return new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());
  }

  public function test_start_inside_a_drain_absorbs_the_event_as_igniter(): void {
    CorrelationContext::set_causation('evt-manual', 'integration_event');

    $process = new FakeThreeStepProcess();
    $this->make_runner()->start($process);

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
    // The #[StartsOn] path marks BEFORE start(); a stale armed slot must not clobber it.
    CorrelationContext::set_causation('evt-ambient', 'integration_event');

    $process = new FakeThreeStepProcess();
    $process->mark_ignited_by('evt-explicit');
    $process->mark_source('event');
    $this->make_runner()->start($process);

    $this->assertSame('evt-explicit', $process->ignited_by_event_id());
  }

  public function test_non_event_causation_is_not_absorbed(): void {
    CorrelationContext::set_causation('5582', 'long_process');

    $process = new FakeThreeStepProcess();
    $this->make_runner()->start($process);

    $this->assertNull($process->ignited_by_event_id(), 'only facts ignite; a process id is not an igniter');
  }
}
