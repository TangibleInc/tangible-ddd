<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Bundle;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * E1 (wave 5, CR-W5CC-5): every consumer's EffectMiddleware locates the
 * IExternalEffectHandler of a handler-class effect in the same compiled
 * locator Tactician's CommandHandlerMiddleware uses (handlers keyed by class,
 * built by HandlerLocatorPass). IExternalEffectHandler services are tagged
 * `tangible_ddd.command_handler` by autoconfiguration, so they are in it.
 *
 * Runs after HandlerLocatorPass and copies that locator into argument 2 of
 * `tangible_ddd.middleware.effect` and of each other consumer's
 * `tangible_ddd.consumer.{name}.middleware.effect`.
 *
 * @internal
 */
final class EffectHandlersPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    if (!$container->hasDefinition('tangible_ddd.middleware.command_handler')) {
      return;
    }
    $locator = $container->getDefinition('tangible_ddd.middleware.command_handler')->getArgument(0);

    $ids = ['tangible_ddd.middleware.effect'];
    foreach ($container->hasParameter('tangible_ddd.consumers') ? (array) $container->getParameter('tangible_ddd.consumers') : [] as $c) {
      if (!($c['primary'] ?? true)) {
        $ids[] = "tangible_ddd.consumer.{$c['name']}.middleware.effect";
      }
    }
    foreach ($ids as $id) {
      if ($container->hasDefinition($id)) {
        $container->getDefinition($id)->replaceArgument(2, $locator);
      }
    }
  }
}
