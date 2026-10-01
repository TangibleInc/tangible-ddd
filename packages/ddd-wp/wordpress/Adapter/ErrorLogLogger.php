<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use Psr\Log\AbstractLogger;

/**
 * The wp PSR-3 logger for the runtime (register 1.4: "error_log logger,
 * the WP_DEBUG check"): every record goes to error_log(), except `debug`
 * and `info`, which only do when WP_DEBUG is on. That is 0.6
 * OutboxProcessor::log_event()'s rule ("only log non-success unless
 * WP_DEBUG"), applied to every runtime class. Context arrays are appended as
 * JSON (json_encode, not the WordPress encoder, which is unguarded outside WP).
 *
 * Registered only when psr/log is installed (HostDefaultsWiring).
 */
final class ErrorLogLogger extends AbstractLogger {

  public function log($level, \Stringable|string $message, array $context = []): void {
    if (in_array((string) $level, ['debug', 'info'], true) && !(defined('WP_DEBUG') && WP_DEBUG)) {
      return;
    }
    $line = (string) $message;
    if ($context !== []) {
      $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    error_log($line);
  }
}
