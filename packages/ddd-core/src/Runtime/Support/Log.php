<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Support;

/**
 * Internal diagnostics sink for runtime classes.
 *
 * Runtime classes take an optional `\Closure(string $message): void`; a host
 * adapts its PSR-3 logger with `fn (string $m) => $logger->warning($m)`.
 * Without one, messages go to PHP's error_log(), so nothing is ever silent.
 *
 * Known pending change: psr/log is not yet a dependency of this tree. Once
 * packaging adds it to the root and ddd-core manifests, wave 2 changes the
 * runtime constructors that take this closure (IntegrationDelivery,
 * ReentrantProcessLock, InMemoryTransactionBoundary, LoggingSignalDispatcher)
 * to `?Psr\Log\LoggerInterface`, and this class becomes the null-logger
 * fallback. See Runtime/API-CHANGE-REQUESTS.md.
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
