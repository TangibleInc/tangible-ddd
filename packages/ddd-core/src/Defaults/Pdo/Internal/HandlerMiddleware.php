<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use League\Tactician\Middleware;
use TangibleDDD\Application\CQRS\HandlerClassNameInflector;

/**
 * The terminal of DurableRuntime's command and query buses, after
 * SelfExecutingCommandMiddleware (register 3.2 order: ... → SelfExecuting →
 * handler). A plain message goes to:
 *
 *   1. the array-form handler keyed by its class: a callable is called with
 *      the message, an object gets ->handle($message);
 *   2. else the naming-convention handler (HandlerClassNameInflector:
 *      ...\Commands\XCommand → ...\CommandHandlers\XHandler) when the
 *      container has it, called with ->handle($message).
 *
 * The return value passes through (D11). No handler → \LogicException
 * naming the message.
 *
 * @internal
 */
final class HandlerMiddleware implements Middleware {

  private readonly HandlerClassNameInflector $inflector;

  public function __construct(private readonly RuntimeContainer $container) {
    $this->inflector = new HandlerClassNameInflector();
  }

  public function execute(object $command, callable $next): mixed {
    $class = get_class($command);
    $handler = $this->container->handler_for($class) ?? $this->conventional($class);
    if ($handler === null) {
      throw new \LogicException("No handler for $class: pass [$class => handler] in DurableRuntime::compose() \$handlers, or a container holding its convention-named handler");
    }
    if (is_callable($handler)) {
      return $handler($command);
    }
    if (!is_callable([$handler, 'handle'])) {
      throw new \LogicException(sprintf('The handler %s of %s has no handle() method', get_class($handler), $class));
    }
    return $handler->handle($command);
  }

  private function conventional(string $class): ?object {
    try {
      $handlerClass = $this->inflector->getClassName($class);
    } catch (\LogicException) {
      return null; // not in a Commands\ or Queries\ namespace
    }
    return $this->container->has($handlerClass) ? $this->container->get($handlerClass) : null;
  }
}
