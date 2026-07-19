<?php

namespace TangibleDDD\Application\Logging;

use League\Tactician\Middleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Infra\IDDDConfig;

/**
 * @deprecated 0.3 — dissolved into the act bracket (CorrelationMiddleware
 * owns guard + scope + the audit record; build ruling #1 in
 * docs/0.3-trace-context.md: the record is written at bracket-open, where
 * the enclosing cause is still visible — two middlewares can't both see the
 * parent and own the scope).
 *
 * Kept as a pass-through ONLY because consumer tactician.yaml chains still
 * reference '@CommandAuditMiddleware' positionally; removing the class would
 * fatal their container compilation. Consumers drop it from their chains at
 * leisure (migration ledger); the class dies when the last chain does.
 */
final class CommandAuditMiddleware implements Middleware {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly EventsUnitOfWork $events,
    private readonly Redactor $redactor
  ) {}

  public function execute($command, callable $next) {
    return $next($command);
  }
}
