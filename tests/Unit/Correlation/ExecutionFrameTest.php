<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;

/**
 * Execution frames on the ambient context: the command frame is marked by
 * CorrelationMiddleware around every dispatch, the process frame by the
 * runner's sealed bracket. Frames are what the legality guards read —
 * command-inside-command throws; command-inside-process-scope is the
 * blessed saga ground contact and passes.
 */
class ExecutionFrameTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_frames_mark_and_clear(): void {
    $this->assertNull(CorrelationContext::command_frame());
    $this->assertNull(CorrelationContext::process_frame());

    CorrelationContext::mark_command_frame('Acme\\DoThing');
    CorrelationContext::mark_process_frame('42');
    $this->assertSame('Acme\\DoThing', CorrelationContext::command_frame());
    $this->assertSame('42', CorrelationContext::process_frame());

    CorrelationContext::clear_command_frame();
    CorrelationContext::clear_process_frame();
    $this->assertNull(CorrelationContext::command_frame());
    $this->assertNull(CorrelationContext::process_frame());
  }

  public function test_reset_clears_frames(): void {
    CorrelationContext::mark_command_frame('Acme\\DoThing');
    CorrelationContext::mark_process_frame('42');
    CorrelationContext::reset();

    $this->assertNull(CorrelationContext::command_frame());
    $this->assertNull(CorrelationContext::process_frame());
  }

  private function make_middleware(): \TangibleDDD\Application\Correlation\CorrelationMiddleware {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn(null);   // audit disabled — frames only
    $wpdb->method('prepare')->willReturnArgument(0);
    $GLOBALS['wpdb'] = $wpdb;

    return new \TangibleDDD\Application\Correlation\CorrelationMiddleware(
      new \TangibleDDD\Infra\DDDConfig(prefix: 'execframe', namespace_root: 'ExecFrame\\Tests', version: 't'),
      new \TangibleDDD\Application\Events\EventsUnitOfWork(),
      new \TangibleDDD\Application\Logging\Redactor(),
    );
  }

  public function test_middleware_marks_frame_for_the_duration_of_the_dispatch(): void {
    $middleware = $this->make_middleware();
    $command = new \stdClass();

    $seen = null;
    $middleware->execute($command, function ($c) use (&$seen) {
      $seen = CorrelationContext::command_frame();
      return 'ok';
    });

    $this->assertSame(\stdClass::class, $seen, 'frame must name the command during its pass');
    $this->assertNull(CorrelationContext::command_frame(), 'frame must clear when the pass ends');
  }

  public function test_frame_clears_even_when_the_handler_throws(): void {
    $middleware = $this->make_middleware();

    try {
      $middleware->execute(new \stdClass(), function () {
        throw new \RuntimeException('boom');
      });
      $this->fail('exception must propagate');
    } catch (\RuntimeException) {
    }

    $this->assertNull(CorrelationContext::command_frame());
  }

  public function test_command_dispatched_inside_command_throws_naming_both(): void {
    $middleware = $this->make_middleware();
    $outer = new class {};
    $inner = new class {};

    try {
      $middleware->execute($outer, function () use ($middleware, $inner) {
        // a handler committing the sin: dispatching a command in-band
        return $middleware->execute($inner, fn () => 'never');
      });
      $this->fail('nested dispatch must throw');
    } catch (CommandDispatchedInsideCommand $e) {
      $this->assertStringContainsString(get_class($inner), $e->getMessage());
      $this->assertStringContainsString(get_class($outer), $e->getMessage());
    }

    $this->assertNull(CorrelationContext::command_frame(), 'violation must not leave a stuck frame');
  }

  public function test_command_inside_process_scope_is_legal(): void {
    $middleware = $this->make_middleware();

    // The runner's bracket: process frame marked, correlation scoped —
    // exactly the context a saga step dispatches its Result commands from.
    CorrelationContext::mark_process_frame('7');
    $result = CorrelationContext::with('saga-corr', function () use ($middleware) {
      return $middleware->execute(new \stdClass(), fn () => 'ground contact');
    });
    CorrelationContext::clear_process_frame();

    $this->assertSame('ground contact', $result);
  }
}
