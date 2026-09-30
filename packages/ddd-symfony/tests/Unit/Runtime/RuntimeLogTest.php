<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use TangibleDDD\Symfony\Runtime\RuntimeLog;

final class RuntimeLogTest extends TestCase {

  private function logger(): LoggerInterface {
    return new class extends AbstractLogger {
      public array $lines = [];
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = (string) $message;
      }
    };
  }

  public function test_a_closure_parameter_gets_an_adapter_that_writes_to_the_logger(): void {
    $class = new class (null) {
      public function __construct(public readonly ?\Closure $log) {}
    };
    $logger = $this->logger();

    $arg = RuntimeLog::argument($class::class, 'log', $logger);

    self::assertInstanceOf(\Closure::class, $arg);
    $arg('hello');
    self::assertSame(['hello'], $logger->lines);
  }

  public function test_a_logger_parameter_gets_the_logger_itself(): void {
    $class = new class (null) {
      public function __construct(public readonly ?LoggerInterface $log) {}
    };
    $logger = $this->logger();

    self::assertSame($logger, RuntimeLog::argument($class::class, 'log', $logger));
  }

  public function test_no_logger_or_no_such_parameter_is_null(): void {
    $class = new class (null) {
      public function __construct(public readonly ?\Closure $log) {}
    };
    self::assertNull(RuntimeLog::argument($class::class, 'log', null));
    self::assertNull(RuntimeLog::argument($class::class, 'other', $this->logger()));
  }
}
