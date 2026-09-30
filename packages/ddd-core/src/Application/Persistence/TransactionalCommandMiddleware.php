<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Persistence;

use League\Tactician\Middleware;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NoTransactionBoundary;

/**
 * The portable transaction middleware (register 1.4, 3.2). The legacy
 * `TransactionMiddleware` (whose constructor takes the WordPress connection)
 * becomes a wp subclass of this in wave 2 (rule R2).
 *
 * Wraps ONLY ITransactionalCommand in ITransactionBoundary::run(); everything
 * else passes through. Return values pass through unchanged (D11).
 *
 * Error behaviour: with no boundary (constructor null and none in
 * HostDefaults) an ITransactionalCommand throws NoTransactionBoundary BEFORE
 * the handler runs; the legacy silent fallback is gone. Boundary errors
 * (NestedTransactionRejected, TransactionFailed, the handler's own exception)
 * propagate unchanged.
 *
 * Order is frozen: Correlation → Transaction → DomainEventsPublish →
 * SelfExecuting → handler.
 */
class TransactionalCommandMiddleware implements Middleware {

  public function __construct(private readonly ?ITransactionBoundary $boundary = null) {}

  public function execute($command, callable $next) {
    if (!$command instanceof ITransactionalCommand) {
      return $next($command);
    }

    $boundary = $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);
    if ($boundary === null) {
      throw new NoTransactionBoundary(sprintf(
        '%s is an ITransactionalCommand but no ITransactionBoundary is configured.',
        get_class($command)
      ));
    }

    return $boundary->run(static fn () => $next($command));
  }
}
