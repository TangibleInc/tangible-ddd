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
    // Enter a correlation scope for this command. enter() inherits an existing
    // correlation (e.g. one a LongProcess run or integration callback has
    // already established) or generates a fresh one for a new top-level command.
    // leave() only tears the context down when the OUTERMOST scope exits, so a
    // command dispatched inside a process/boundary scope no longer wipes the
    // correlation out from under the work that wraps it.
    CorrelationContext::enter();

    try {
      return $next($command);
    } finally {
      CorrelationContext::leave();
    }
  }
}
