<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use League\Tactician\Middleware;

/**
 * The innermost bus stage for scenarios: resolves a command's handler from
 * a class => callable map and returns its value unchanged. Hosts with a
 * container-backed handler middleware may use theirs instead.
 */
final class HandlerMapMiddleware implements Middleware {

  /** @param array<class-string, callable(object): mixed> $handlers */
  public function __construct(private readonly array $handlers) {}

  public function execute($command, callable $next) {
    $handler = $this->handlers[get_class($command)]
      ?? throw new \LogicException(sprintf('No scenario handler for %s', get_class($command)));
    return $handler($command);
  }
}
