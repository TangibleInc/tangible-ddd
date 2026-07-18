<?php

namespace TangibleDDD\Application\Correlation;

use League\Tactician\Middleware;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;

/**
 * Tactician middleware that manages the correlation context lifecycle.
 *
 * This middleware:
 * 1. Refuses nested dispatch — commands are the atomic moments of the system
 *    and never nest (check-before-mark on the command frame)
 * 2. Ensures correlation context exists (generates new ID if not set)
 * 3. Marks the command frame for the duration of the pass — the legality
 *    signal the process-start guard reads
 * 4. Resets context after command completes to prevent leakage
 *
 * For integration events, correlation is set by the integration action handler
 * before the command is dispatched. This middleware just ensures a fallback
 * for commands dispatched directly (admin UI, REST API, etc).
 */
final class CorrelationMiddleware implements Middleware {

  public function execute($command, callable $next) {
    // No command inside a command. Enforced here — not in the audit
    // middleware — because this middleware runs even where command_audit
    // is disabled, so the invariant holds on every install.
    if (null !== $inside = CorrelationContext::command_frame()) {
      throw new CommandDispatchedInsideCommand(get_class($command), $inside);
    }

    // Enter a correlation scope for this command. enter() inherits an existing
    // correlation (e.g. one a LongProcess run or integration callback has
    // already established) or generates a fresh one for a new top-level command.
    // leave() only tears the context down when the OUTERMOST scope exits, so a
    // command dispatched inside a process/boundary scope no longer wipes the
    // correlation out from under the work that wraps it.
    CorrelationContext::enter();
    CorrelationContext::mark_command_frame(get_class($command));

    try {
      return $next($command);
    } finally {
      CorrelationContext::clear_command_frame();
      CorrelationContext::leave();
    }
  }
}
