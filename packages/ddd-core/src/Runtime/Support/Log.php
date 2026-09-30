<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Support;

/**
 * Internal diagnostics sink for runtime classes.
 *
 * Runtime classes take an optional `\Closure(string $message): void`; a host
 * adapts its PSR-3 logger with `fn (string $m) => $logger->warning($m)`.
 * Without one, messages go to PHP's error_log(), so nothing is ever silent.
 * (psr/log is not yet a dependency of this tree; see the api change request.)
 *
 * @internal
 */
final class Log {

  /** @param (\Closure(string):void)|null $sink */
  public static function write(?\Closure $sink, string $message): void {
    if ($sink !== null) {
      $sink($message);
      return;
    }
    error_log($message);
  }
}
