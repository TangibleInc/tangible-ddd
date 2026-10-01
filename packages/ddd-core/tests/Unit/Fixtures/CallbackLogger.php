<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that forwards each message to a callback (tests that collect messages into an array). */
final class CallbackLogger extends AbstractLogger {

  /** @param \Closure(string): void $callback */
  public function __construct(private readonly \Closure $callback) {}

  /** Signature valid against psr/log 1, 2 and 3. @param string|\Stringable $message */
  public function log($level, $message, array $context = []): void {
    ($this->callback)((string) $message);
  }
}
