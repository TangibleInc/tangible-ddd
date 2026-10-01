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
 *   3. PHP's error_log() for notice and above, so no problem is ever
 *      silent; debug/info records are dropped when there is no logger.
 *
 * The wave-1 `\Closure(string $message): void` form was accepted for one
 * round (CR-SP-1) and is removed in wave 3: pass a LoggerInterface.
 *
 * Never throws for a well-formed sink; a throwing logger propagates, as a
 * logger bug is the host's to see.
 *
 * @internal
 */
final class Log {

  /** @param string $level a PSR-3 level */
  public static function write(?LoggerInterface $sink, string $message, string $level = 'warning'): void {
    $sink ??= self::hostLogger();
    if ($sink !== null) {
      $sink->log($level, $message);
      return;
    }

    // error_log() is the never-silent floor for problems, not a debug
    // channel: without any logger, debug/info records are dropped.
    if ($level === 'debug' || $level === 'info') {
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
