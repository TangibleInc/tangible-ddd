<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CQRS;

use League\Tactician\Middleware;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandWrapsHandler;
use TangibleDDD\Application\Exceptions\UnresolvableHandleDependency;
use TangibleDDD\Application\Queries\SelfHandlingQuery;

/**
 * Runs a SelfHandlingCommand's or SelfHandlingQuery's own handle() (spec §14
 * item 1). Sits in each bus's onion IMMEDIATELY BEFORE the naming-convention
 * handler resolver — in the COMMAND bus before
 * `tactician.middleware.command_handler` (so a self-handling command still
 * passes through the act bracket, the transaction, and domain-event
 * publishing), and in the QUERY bus before
 * `tactician.middleware.query_handler` (where it is the only other
 * middleware: the query bus deliberately carries no act bracket) — then
 * short-circuits before the resolver would look for a separate handler class
 * that does not exist.
 *
 * ONE middleware, BOTH bases, explicit union check: `SelfHandlingCommand ||
 * SelfHandlingQuery` rather than a shared marker interface. A marker would
 * be new public naming surface (a name consumers would type and we would
 * owe compatibility on) for zero behavior — per the naming rulings, that
 * needs an owner ruling; the union check does not. The class KEEPS its
 * command-era name for the same reason: renaming it would be a break with
 * no behavior attached.
 *
 * Dispatch decision:
 *  - neither base            → `$next($command)` unchanged; plain commands
 *    and queries route to their convention-named handlers like before.
 *  - either base             → this middleware is the TERMINAL. It does NOT
 *    call `$next` (that would reach the resolver and fail to find a handler
 *    class). It reflects handle(), method-injects its dependencies, invokes
 *    it, and propagates the return value up the onion.
 *
 * The return-value asymmetry lives in the bases, not here: a command's
 * handle() is void-by-default (the receipt rule → the pass yields null); a
 * query's handle() returns the read result (that is what a query IS). This
 * middleware just propagates whatever handle() returns.
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
    if (!$command instanceof SelfHandlingCommand && !$command instanceof SelfHandlingQuery) {
      return $next($command);
    }

    if (!method_exists($command, 'handle')) {
      throw new SelfHandlingCommandHasNoHandler($command::class);
    }

    $method = new ReflectionMethod($command, 'handle');
    $args = $this->resolve_arguments($command, $method);

    // The act lane (0.6.4), COMMANDS ONLY — queries must not record. Attach
    // the same live UoW instance the container holds (and the command
    // middleware seals/drains) so handle() can $this->event() coordination
    // facts. Absent service → nothing attached → a raise throws in
    // RaisesEvents naming the command, never a silent drop.
    if ($command instanceof SelfHandlingCommand
      && $this->container->has(\TangibleDDD\Application\Events\EventsUnitOfWork::class)) {
      $command->attach_events_uow(
        $this->container->get(\TangibleDDD\Application\Events\EventsUnitOfWork::class)
      );
    }

    // Explicit though a no-op in PHP 8.1+: protected handle() is reachable
    // here and only here — no public escape hatch for manual callers.
    $method->setAccessible(true);

    // Propagate whatever handle() returns — a command's receipt (void →
    // null), a query's read result.
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
        // Conformance guard: a self-handling command method-injecting an
        // ICommandHandler is wrapping the two-class shape inside the
        // self-handling one. Fires before the container is consulted — a
        // resolvable handler is no less a chimera.
        if (is_a($type->getName(), ICommandHandler::class, true)) {
          throw new SelfHandlingCommandWrapsHandler($command::class, $type->getName());
        }

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
