<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Support;

use Psr\Log\LoggerInterface;
use TangibleDDD\Runtime\HostDefaults;

/**
 * Internal diagnostics sink for runtime classes.
 *
 * Runtime classes take an optional `?Psr\Log\LoggerInterface` (wave 2,
 * wave1-notes "Logging"). Resolution, first match wins:
 *
 *   1. the logger the class was constructed with;
 *   2. the host logger, `HostDefaults::get(LoggerInterface::class)`;
 *   3. PHP's error_log(), so nothing is ever silent.
 *
 * Transitional: the wave-1 form `\Closure(string $message): void` is still
 * accepted for one round, so callers built against wave 1 (the conformance
 * mem host) keep working. It is deprecated; pass a LoggerInterface
 * (CR-SP-1 in docs/extraction/wave2-split-ports-change-requests.md).
 *
 * Never throws for a well-formed sink; a throwing logger propagates, as a
 * logger bug is the host's to see.
 *
 * @internal
 */
final class Log {

  /**
   * @param LoggerInterface|(\Closure(string):void)|null $sink
   * @param string $level a PSR-3 level; used for LoggerInterface sinks only
   */
  public static function write(LoggerInterface|\Closure|null $sink, string $message, string $level = 'warning'): void {
    if ($sink instanceof \Closure) {
      $sink($message);
      return;
    }

    $sink ??= self::hostLogger();
    if ($sink !== null) {
      $sink->log($level, $message);
      return;
    }

    error_log($message);
  }

  private static function hostLogger(): ?LoggerInterface {
    if (!interface_exists(LoggerInterface::class)) {
      return null;
    }
    $logger = HostDefaults::get(LoggerInterface::class);
    return $logger instanceof LoggerInterface ? $logger : null;
  }
}
