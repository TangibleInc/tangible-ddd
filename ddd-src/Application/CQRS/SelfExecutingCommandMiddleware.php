<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CQRS;

use League\Tactician\Middleware;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler;
use TangibleDDD\Application\Exceptions\UnresolvableHandleDependency;

/**
 * Runs a SelfHandlingCommand's own handle() (spec §14 item 1). Sits in the
 * onion IMMEDIATELY BEFORE the naming-convention handler resolver
 * (CommandHandlerMiddleware), so a self-handling command still passes through
 * the act bracket, the transaction, and domain-event publishing — then this
 * middleware short-circuits before the resolver would look for a separate
 * handler class that does not exist.
 *
 * Dispatch decision:
 *  - NOT a SelfHandlingCommand  → `$next($command)` unchanged; the command
 *    routes to its convention-named handler like any plain command.
 *  - a SelfHandlingCommand      → this middleware is the TERMINAL. It does
 *    NOT call `$next` (that would reach the resolver and fail to find a
 *    handler class). It reflects handle(), method-injects its dependencies,
 *    invokes it, and propagates the return value up the onion.
 *
 * Method injection (Symfony has no `container->call()`, so we do it here, the
 * way Symfony's ArgumentResolver does): each handle() parameter is resolved
 * by its named, non-builtin class type via `$container->get($type)`. A param
 * that is untyped, builtin, or a union/intersection type falls back to its
 * default value if it has one, otherwise it is unresolvable.
 */
final class SelfExecutingCommandMiddleware implements Middleware {

  public function __construct(
    private readonly ContainerInterface $container
  ) {}

  public function execute($command, callable $next) {
    if (!$command instanceof SelfHandlingCommand) {
      return $next($command);
    }

    if (!method_exists($command, 'handle')) {
      throw new SelfHandlingCommandHasNoHandler($command::class);
    }

    $method = new ReflectionMethod($command, 'handle');
    $args = $this->resolve_arguments($command, $method);

    // Explicit though a no-op in PHP 8.1+: protected handle() is reachable
    // here and only here — no public escape hatch for manual callers.
    $method->setAccessible(true);

    // Propagate whatever handle() returns (void → null) — the receipt rule.
    return $method->invokeArgs($command, $args);
  }

  /**
   * @return array<int, mixed>
   */
  private function resolve_arguments(object $command, ReflectionMethod $method): array {
    $args = [];

    foreach ($method->getParameters() as $param) {
      $type = $param->getType();

      if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
        $args[] = $this->container->get($type->getName());
        continue;
      }

      if ($param->isDefaultValueAvailable()) {
        $args[] = $param->getDefaultValue();
        continue;
      }

      throw new UnresolvableHandleDependency($command::class, $param->getName());
    }

    return $args;
  }
}
