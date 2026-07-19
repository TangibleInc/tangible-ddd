<?php

namespace TangibleDDD\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\CommandAuditMiddleware;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * The deprecation contract (0.3): CommandAuditMiddleware dissolved into the
 * act bracket (CorrelationMiddleware — see ActBracketTest for the audit
 * behaviors: preflight with the ENCLOSING cause, redaction, events capture,
 * error finalise). This shell survives only because consumer tactician
 * chains reference it positionally; it must do exactly nothing.
 */
class CommandAuditMiddlewareTest extends TestCase {

  public function test_passes_through_and_returns_the_result(): void {
    $middleware = new CommandAuditMiddleware(new FakeDDDConfig(), new EventsUnitOfWork(), new Redactor());

    $seen = null;
    $result = $middleware->execute(new \stdClass(), static function ($c) use (&$seen) {
      $seen = $c;
      return 42;
    });

    $this->assertSame(42, $result);
    $this->assertInstanceOf(\stdClass::class, $seen);
  }

  public function test_writes_nothing_even_when_audit_is_enabled(): void {
    $inserts = [];
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn('wp_test_command_audit');
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('insert')->willReturnCallback(function () use (&$inserts) {
      $inserts[] = func_get_args();
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;

    $middleware = new CommandAuditMiddleware(new FakeDDDConfig(), new EventsUnitOfWork(), new Redactor());
    $middleware->execute(new \stdClass(), static fn () => 'ok');

    $this->assertSame([], $inserts, 'the act bracket owns the audit record now');
  }

  public function test_exceptions_pass_through_untouched(): void {
    $middleware = new CommandAuditMiddleware(new FakeDDDConfig(), new EventsUnitOfWork(), new Redactor());

    $this->expectException(\RuntimeException::class);
    $middleware->execute(new \stdClass(), static function (): void {
      throw new \RuntimeException('boom');
    });
  }
}
