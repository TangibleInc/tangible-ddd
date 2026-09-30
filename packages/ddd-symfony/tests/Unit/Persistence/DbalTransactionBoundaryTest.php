<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;

final class DbalTransactionBoundaryTest extends TestCase {

  public function test_a_rollback_failure_is_logged_and_the_original_exception_surfaces(): void {
    $conn = $this->createMock(Connection::class);
    $conn->method('isTransactionActive')->willReturnOnConsecutiveCalls(false, true);
    $conn->expects(self::once())->method('beginTransaction');
    $conn->method('rollBack')->willThrowException(new \RuntimeException('connection lost'));

    $logger = new class extends AbstractLogger {
      public array $lines = [];
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = [$level, (string) $message];
      }
    };

    $original = new \DomainException('the handler failed');
    try {
      (new DbalTransactionBoundary($conn, logger: $logger))->run(static fn () => throw $original);
      self::fail('expected the original');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }

    self::assertCount(1, $logger->lines);
    self::assertStringContainsString('ROLLBACK failed', $logger->lines[0][1]);
    self::assertStringContainsString('the handler failed', $logger->lines[0][1]);
  }

  public function test_a_failed_begin_is_a_transaction_failure(): void {
    $conn = $this->createMock(Connection::class);
    $conn->method('isTransactionActive')->willReturn(false);
    $conn->method('beginTransaction')->willThrowException(new \RuntimeException('no connection'));

    $ran = false;
    try {
      (new DbalTransactionBoundary($conn))->run(function () use (&$ran) {
        $ran = true;
      });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertSame('no connection', $e->getPrevious()?->getMessage());
    }
    self::assertFalse($ran);
  }
}
