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
}
