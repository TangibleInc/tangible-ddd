<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;

class CorrelationMiddlewareTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_generates_correlation_if_not_set(): void {
    $middleware = new CorrelationMiddleware();
    $captured_id = null;

    $middleware->execute(new \stdClass(), function () use (&$captured_id) {
      $captured_id = CorrelationContext::get();
      return 'ok';
    });

    $this->assertNotNull($captured_id);
    $this->assertNotEmpty($captured_id);
  }

  public function test_preserves_existing_correlation(): void {
    CorrelationContext::init('pre-existing');
    $middleware = new CorrelationMiddleware();
    $captured_id = null;

    $middleware->execute(new \stdClass(), function () use (&$captured_id) {
      $captured_id = CorrelationContext::get();
      return 'ok';
    });

    $this->assertSame('pre-existing', $captured_id);
  }

  public function test_resets_context_after_command(): void {
    $middleware = new CorrelationMiddleware();

    $middleware->execute(new \stdClass(), fn() => 'ok');

    $this->assertNull(CorrelationContext::peek());
  }

  public function test_resets_context_even_on_exception(): void {
    $middleware = new CorrelationMiddleware();

    try {
      $middleware->execute(new \stdClass(), function () {
        throw new \RuntimeException('fail');
      });
    } catch (\RuntimeException) {}

    $this->assertNull(CorrelationContext::peek());
  }

  public function test_returns_handler_result(): void {
    $middleware = new CorrelationMiddleware();
    $result = $middleware->execute(new \stdClass(), fn() => 42);
    $this->assertSame(42, $result);
  }

  public function test_rethrows_handler_exception(): void {
    $middleware = new CorrelationMiddleware();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('boom');

    $middleware->execute(new \stdClass(), function () {
      throw new \RuntimeException('boom');
    });
  }

  /**
   * A LongProcess step is designed to issue multiple commands via
   * Result->commands; ProcessRunner dispatches them by looping $command->send(),
   * each flowing through this middleware. The runner wraps the run in a
   * correlation scope (CorrelationContext::with). Both commands must therefore
   * observe the SAME saga correlation — the first command's scope exit must not
   * tear down the outer process scope.
   *
   * Before the scoped fix this failed: the first command's unconditional reset
   * wiped the context and the second command generated a fresh, unrelated id,
   * shredding the saga's trace.
   */
  public function test_multiple_commands_within_a_process_scope_share_correlation(): void {
    $middleware = new CorrelationMiddleware();
    $seen = [];

    // Model the fixed ProcessRunner: it wraps the run (and the commands a step
    // dispatches) in a correlation scope.
    CorrelationContext::with('saga-correlation', function () use ($middleware, &$seen) {
      $middleware->execute(new \stdClass(), function () use (&$seen) {
        $seen['cmd_a'] = CorrelationContext::get();
        return 'ok';
      });
      $middleware->execute(new \stdClass(), function () use (&$seen) {
        $seen['cmd_b'] = CorrelationContext::get();
        return 'ok';
      });
    });

    $this->assertSame('saga-correlation', $seen['cmd_a'], 'first command runs under the saga correlation');
    $this->assertSame('saga-correlation', $seen['cmd_b'], 'second command stays in the same saga trace');
    $this->assertNull(CorrelationContext::peek(), 'scope fully cleared after the process run');
  }

  /**
   * A top-level command (no surrounding scope) still generates its own
   * correlation and fully clears it on exit — the pre-existing contract.
   */
  public function test_top_level_command_generates_and_clears_correlation(): void {
    $middleware = new CorrelationMiddleware();
    $seen = null;

    $middleware->execute(new \stdClass(), function () use (&$seen) {
      $seen = CorrelationContext::get();
      return 'ok';
    });

    $this->assertNotEmpty($seen, 'top-level command got a correlation');
    $this->assertNull(CorrelationContext::peek(), 'context cleared after a top-level command');
  }
}
