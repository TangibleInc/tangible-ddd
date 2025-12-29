<?php

namespace TangibleDDD\Application\Correlation;

use League\Tactician\Middleware;

/**
 * Tactician middleware that manages the correlation context lifecycle.
 *
 * This middleware:
 * 1. Ensures correlation context exists (generates new ID if not set)
 * 2. Resets context after command completes to prevent leakage
 *
 * For integration events, correlation is set by the integration action handler
 * before the command is dispatched. This middleware just ensures a fallback
 * for commands dispatched directly (admin UI, REST API, etc).
 */
final class CorrelationMiddleware implements Middleware {

  public function execute($command, callable $next) {
    // Initialize correlation if not already set (e.g., from integration handler)
    if (CorrelationContext::peek() === null) {
      CorrelationContext::init();
    }

    try {
      return $next($command);
    } finally {
      CorrelationContext::reset();
    }
  }
}
