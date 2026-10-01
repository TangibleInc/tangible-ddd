<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps the runtime's diagnostics for the scenario instead of error_log(). */
final class BufferLogger extends AbstractLogger {

  /** @var list<string> */
  public array $lines = [];

  public function log($level, $message, array $context = []): void {
    $this->lines[] = sprintf('[%s] %s', (string) $level, (string) $message);
  }
}
