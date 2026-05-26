<?php

namespace TangibleDDD\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\ITransactionalCommand;
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

  public function test_handler_result_returned_on_success(): void {
    $wpdb = $this->make_wpdb();
    $middleware = new TransactionMiddleware($wpdb);

    $command = $this->createMock(ITransactionalCommand::class);
    $result = $middleware->execute($command, fn() => ['data' => 42]);

    $this->assertSame(['data' => 42], $result);
  }
}
