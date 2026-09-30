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
    $level = 0;
    $conn->method('isTransactionActive')->willReturnCallback(static function () use (&$level) {
      return $level > 0;
    });
    $conn->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level) {
      return $level;
    });
    $conn->expects(self::once())->method('beginTransaction')->willReturnCallback(static function () use (&$level): void {
      $level++;
    });
    $conn->method('rollBack')->willReturnCallback(static function () use (&$level): void {
      $level = 0; // DBAL resets the level before the driver ROLLBACK
      throw new \RuntimeException('connection lost');
    });

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

  /**
   * DBAL 4 decrements the nesting level in the finally of commit(), so after a
   * failed RELEASE SAVEPOINT the connection is back at the caller's level. The
   * boundary must roll back its own savepoint by name and never call
   * rollBack(), which would end the caller's outer transaction.
   */
  public function test_a_failed_release_in_savepoint_mode_never_rolls_back_the_outer_transaction(): void {
    $level = 1;
    $conn = $this->createMock(Connection::class);
    $conn->method('isTransactionActive')->willReturnCallback(static function () use (&$level) {
      return $level > 0;
    });
    $conn->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level) {
      return $level;
    });
    $conn->method('beginTransaction')->willReturnCallback(static function () use (&$level): void {
      $level++;
    });
    $conn->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));
    $conn->method('commit')->willReturnCallback(static function () use (&$level): void {
      $level--;
      throw new \RuntimeException('RELEASE SAVEPOINT failed');
    });
    $conn->expects(self::never())->method('rollBack');
    $conn->expects(self::once())->method('rollbackSavepoint')->with('DOCTRINE_2');

    try {
      (new DbalTransactionBoundary($conn, \TangibleDDD\Runtime\NestedPolicy::Savepoint))->run(static fn () => 'ok');
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertSame('RELEASE SAVEPOINT failed', $e->getPrevious()?->getMessage());
    }
    self::assertSame(1, $level);
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
