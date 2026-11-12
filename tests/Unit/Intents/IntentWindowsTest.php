<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Intents\CaptureOutsideAct;
use TangibleDDD\Application\Intents\IntentWindows;
use TangibleDDD\Application\Intents\Intents;
use TangibleDDD\Application\Intents\IntentsMiddlewareMissing;
use TangibleDDD\Tests\Fakes\FakeCapturingCommand;

class IntentWindowsTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    IntentWindows::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
    IntentWindows::reset();
  }

  private function in_act(callable $work): mixed {
    return Correlation::within(TraceContext::root()->for_act('act1', 'Outer'), $work);
  }

  public function test_capture_outside_an_act_throws(): void {
    $this->expectException(CaptureOutsideAct::class);
    Intents::capture(fn() => null);
  }

  public function test_capture_in_an_unwatched_act_throws(): void {
    $this->expectException(IntentsMiddlewareMissing::class);
    $this->expectExceptionMessage('IntentsMiddleware');
    $this->in_act(fn() => Intents::capture(fn() => null));
  }

  public function test_try_capture_without_an_open_window_is_false(): void {
    IntentWindows::watch('act1');
    $this->assertFalse($this->in_act(fn() => IntentWindows::try_capture('act1', new FakeCapturingCommand(1))));
    $this->assertSame([], IntentWindows::undecided('act1'));
  }

  public function test_try_capture_ignores_a_non_command(): void {
    IntentWindows::watch('act1');
    IntentWindows::open('act1');
    $this->assertFalse(IntentWindows::try_capture('act1', new \stdClass()));
    $this->assertCount(0, IntentWindows::close('act1'));
  }

  public function test_nested_windows_return_only_their_own(): void {
    IntentWindows::watch('act1');
    $a = new FakeCapturingCommand(1);
    $b = new FakeCapturingCommand(2);

    $inner = null;
    $outer = $this->in_act(function() use ($a, $b, &$inner) {
      return Intents::capture(function() use ($a, $b, &$inner) {
        IntentWindows::try_capture('act1', $a);
        $inner = Intents::capture(fn() => IntentWindows::try_capture('act1', $b));
      });
    });

    $this->assertSame([$b], array_map(fn($i) => $i->command, $inner->all()));
    $this->assertSame([$a], array_map(fn($i) => $i->command, $outer->all()));
    $this->assertCount(2, IntentWindows::record('act1'));
    $this->assertSame(FakeCapturingCommand::class, IntentWindows::record('act1')[0]['command']);
  }

  public function test_origin_is_the_senders_file_and_line(): void {
    IntentWindows::watch('act1');
    IntentWindows::open('act1');
    $line = __LINE__ + 1;
    IntentWindows::try_capture('act1', new FakeCapturingCommand(1));
    $captured = IntentWindows::close('act1')->all();

    $this->assertSame(__FILE__ . ':' . $line, $captured[0]->origin);
  }

  public function test_a_throwing_call_leaves_its_intents_undecided(): void {
    IntentWindows::watch('act1');
    $a = new FakeCapturingCommand(1);

    try {
      $this->in_act(fn() => Intents::capture(function() use ($a) {
        IntentWindows::try_capture('act1', $a);
        throw new \RuntimeException('boom');
      }));
      $this->fail('expected the throw to propagate');
    } catch( \RuntimeException $e ) {
      $this->assertSame('boom', $e->getMessage());
    }

    $undecided = IntentWindows::undecided('act1');
    $this->assertCount(1, $undecided);
    $this->assertSame($a, $undecided[0]->command);
  }
}
