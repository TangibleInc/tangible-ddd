<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\DDDConfig;

class CorrelationMiddlewareTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  /** The act bracket, audit-disabled (guard + scope behavior only). */
  private function make_middleware(): CorrelationMiddleware {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn(null);
    $wpdb->method('prepare')->willReturnArgument(0);
    $GLOBALS['wpdb'] = $wpdb;

    return new CorrelationMiddleware(
      new DDDConfig(prefix: 'corrmw', namespace_root: 'CorrMw\\Tests', version: 't'),
      new EventsUnitOfWork(),
      new Redactor(),
    );
  }

  public function test_generates_correlation_if_not_set(): void {
    $middleware = $this->make_middleware();
    $captured_id = null;

    $middleware->execute(new \stdClass(), function () use (&$captured_id) {
      $captured_id = Correlation::current()->correlation_id;
      return 'ok';
    });

    $this->assertNotNull($captured_id);
    $this->assertNotEmpty($captured_id);
  }

  public function test_preserves_existing_correlation(): void {
    $middleware = $this->make_middleware();
    $captured_id = null;

    Correlation::within(new TraceContext('pre-existing'), function () use ($middleware, &$captured_id) {
      $middleware->execute(new \stdClass(), function () use (&$captured_id) {
        $captured_id = Correlation::current()->correlation_id;
        return 'ok';
      });
    });

    $this->assertSame('pre-existing', $captured_id);
  }

  public function test_resets_context_after_command(): void {
    $middleware = $this->make_middleware();

    $middleware->execute(new \stdClass(), fn() => 'ok');

    $this->assertNull(Correlation::peek());
  }

  public function test_resets_context_even_on_exception(): void {
    $middleware = $this->make_middleware();

    try {
      $middleware->execute(new \stdClass(), function () {
        throw new \RuntimeException('fail');
      });
    } catch (\RuntimeException) {}

    $this->assertNull(Correlation::peek());
  }

  public function test_returns_handler_result(): void {
    $middleware = $this->make_middleware();
    $result = $middleware->execute(new \stdClass(), fn() => 42);
    $this->assertSame(42, $result);
  }

  public function test_rethrows_handler_exception(): void {
    $middleware = $this->make_middleware();

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
   * correlation scope (Correlation::within). Both commands must therefore
   * observe the SAME saga correlation — the first command's scope exit must not
   * tear down the outer process scope.
   *
   * Before the scoped fix this failed: the first command's unconditional reset
   * wiped the context and the second command generated a fresh, unrelated id,
   * shredding the saga's trace.
   */
  public function test_multiple_commands_within_a_process_scope_share_correlation(): void {
    $middleware = $this->make_middleware();
    $seen = [];

    // Model the fixed ProcessRunner: it wraps the run (and the commands a step
    // dispatches) in a correlation scope.
    Correlation::within(new TraceContext('saga-correlation'), function () use ($middleware, &$seen) {
      $middleware->execute(new \stdClass(), function () use (&$seen) {
        $seen['cmd_a'] = Correlation::current()->correlation_id;
        return 'ok';
      });
      $middleware->execute(new \stdClass(), function () use (&$seen) {
        $seen['cmd_b'] = Correlation::current()->correlation_id;
        return 'ok';
      });
    });

    $this->assertSame('saga-correlation', $seen['cmd_a'], 'first command runs under the saga correlation');
    $this->assertSame('saga-correlation', $seen['cmd_b'], 'second command stays in the same saga trace');
    $this->assertNull(Correlation::peek(), 'scope fully cleared after the process run');
  }

  /**
   * A top-level command (no surrounding scope) still generates its own
   * correlation and fully clears it on exit — the pre-existing contract.
   */
  public function test_top_level_command_generates_and_clears_correlation(): void {
    $middleware = $this->make_middleware();
    $seen = null;

    $middleware->execute(new \stdClass(), function () use (&$seen) {
      $seen = Correlation::current()->correlation_id;
      return 'ok';
    });

    $this->assertNotEmpty($seen, 'top-level command got a correlation');
    $this->assertNull(Correlation::peek(), 'context cleared after a top-level command');
  }
}
