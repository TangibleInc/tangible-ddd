<?php

namespace TangibleDDD\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Persistence\TransactionMiddleware;

class TransactionMiddlewareTest extends TestCase {

  private array $queries = [];

  private function make_wpdb(): \wpdb {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('query')->willReturnCallback(function (string $sql) {
      $this->queries[] = $sql;
      return true;
    });
    return $wpdb;
  }

  public function test_transactional_command_wrapped_in_transaction(): void {
    $wpdb = $this->make_wpdb();
    $middleware = new TransactionMiddleware($wpdb);

    $command = $this->createMock(ITransactionalCommand::class);
    $result = $middleware->execute($command, fn() => 'ok');

    $this->assertSame('ok', $result);
    $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->queries);
  }

  public function test_non_transactional_command_skips_transaction(): void {
    $wpdb = $this->make_wpdb();
    $middleware = new TransactionMiddleware($wpdb);

    $command = new \stdClass();
    $result = $middleware->execute($command, fn() => 'ok');

    $this->assertSame('ok', $result);
    $this->assertEmpty($this->queries);
  }

  public function test_rollback_on_exception(): void {
    $wpdb = $this->make_wpdb();
    $middleware = new TransactionMiddleware($wpdb);

    $command = $this->createMock(ITransactionalCommand::class);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('handler failed');

    try {
      $middleware->execute($command, function () {
        throw new \RuntimeException('handler failed');
      });
    } finally {
      $this->assertSame(['START TRANSACTION', 'ROLLBACK'], $this->queries);
    }
  }

  public function test_rollback_error_is_suppressed(): void {
    $wpdb = $this->createMock(\wpdb::class);
    $call_count = 0;
    $wpdb->method('query')->willReturnCallback(function (string $sql) use (&$call_count) {
      $call_count++;
      if ($sql === 'ROLLBACK') {
        throw new \RuntimeException('rollback failed');
      }
      return true;
    });

    $middleware = new TransactionMiddleware($wpdb);
    $command = $this->createMock(ITransactionalCommand::class);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('handler failed');

    $middleware->execute($command, function () {
      throw new \RuntimeException('handler failed');
    });
  }

  // ── R2 legacy subclass (register 1.3, 1.4) ────────────────────────────

  public function test_it_is_the_wp_subclass_of_the_core_transactional_middleware(): void {
    $middleware = new TransactionMiddleware($this->make_wpdb());

    $this->assertInstanceOf(TransactionalCommandMiddleware::class, $middleware);
    $this->assertInstanceOf(\League\Tactician\Middleware::class, $middleware);
  }

  public function test_outside_wordpress_the_no_argument_constructor_throws_a_type_error(): void {
    $previous = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = null;

    try {
      $this->expectException(\TypeError::class);
      new TransactionMiddleware();
    } finally {
      $GLOBALS['wpdb'] = $previous;
    }
  }

  public function test_the_no_argument_constructor_uses_the_global_connection(): void {
    $previous = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = $this->make_wpdb();

    try {
      $result = (new TransactionMiddleware())->execute($this->createMock(ITransactionalCommand::class), fn() => 'ok');
    } finally {
      $GLOBALS['wpdb'] = $previous;
    }

    $this->assertSame('ok', $result);
    $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->queries);
  }

  public function test_start_and_commit_results_stay_unchecked(): void {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('query')->willReturnCallback(function (string $sql) {
      $this->queries[] = $sql;
      return false; // wpdb reports failure by returning false; 0.6 never looked
    });

    $result = (new TransactionMiddleware($wpdb))->execute($this->createMock(ITransactionalCommand::class), fn() => 'ok');

    $this->assertSame('ok', $result);
    $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->queries);
  }

  public function test_a_nested_transactional_command_issues_its_own_start_and_commit_as_0_6_did(): void {
    $middleware = new TransactionMiddleware($this->make_wpdb());
    $command = $this->createMock(ITransactionalCommand::class);

    $middleware->execute($command, fn() => $middleware->execute($command, fn() => 'inner'));

    $this->assertSame(['START TRANSACTION', 'START TRANSACTION', 'COMMIT', 'COMMIT'], $this->queries);
  }

  public function test_handler_result_returned_on_success(): void {
    $wpdb = $this->make_wpdb();
    $middleware = new TransactionMiddleware($wpdb);

    $command = $this->createMock(ITransactionalCommand::class);
    $result = $middleware->execute($command, fn() => ['data' => 42]);

    $this->assertSame(['data' => 42], $result);
  }
}
