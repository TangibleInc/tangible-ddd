<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use League\Tactician\Handler\Mapping\CommandToHandlerMapping;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\CQRS\HandlerClassNameInflector;

/**
 * Where DurableRuntime's EffectMiddleware finds the IExternalEffectHandler
 * of a handler-class effect (E1, wave 5), the way the runtime's
 * HandlerMiddleware finds a command's handler:
 *
 *   1. the array-form handler keyed by the effect command's class
 *      ([RefundCharge::class => new RefundChargeHandler()]);
 *   2. else the naming-convention handler (HandlerClassNameInflector:
 *      ...\Commands\XCommand → ...\CommandHandlers\XHandler) from the
 *      runtime container.
 *
 * Both the locator and the mapping EffectMiddleware takes; an effect with
 * neither is refused by EffectMiddleware with NoEffectHandler before it
 * performs.
 *
 * @internal
 */
final class EffectHandlers implements ContainerInterface, CommandToHandlerMapping {

  public function __construct(private readonly RuntimeContainer $container) {}

  public function getClassName(string $commandClassName): string {
    return $this->container->handler_for($commandClassName) !== null
      ? $commandClassName
      : (new HandlerClassNameInflector())->getClassName($commandClassName);
  }

  public function getMethodName(string $commandClassName): string {
    return 'perform';
  }

  public function get(string $id): mixed {
    return $this->container->handler_for($id) ?? $this->container->get($id);
  }

  public function has(string $id): bool {
    return $this->container->handler_for($id) !== null || $this->container->has($id);
  }
}
