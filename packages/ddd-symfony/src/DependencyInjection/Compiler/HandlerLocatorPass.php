<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Symfony\DependencyInjection\DddTags;

/**
 * Replaces the WordPress wiring's `@service_container` (E S2): in a Symfony
 * kernel application services are private, so Tactician's handler lookup and
 * SelfExecutingCommandMiddleware's handle() method injection get compiled
 * ServiceLocators instead.
 *
 * - `tangible_ddd.command_handlers` / `tangible_ddd.query_handlers`: tagged
 *   handlers keyed by their CLASS (what HandlerClassNameInflector returns).
 * - `tangible_ddd.handle_dependencies`: every service or alias whose id is a
 *   class or interface name (Symfony's autowiring ids), for handle(Type $x)
 *   injection. References are runtime-invalid, so a broken service fails only
 *   when a handle() actually asks for it.
 */
final class HandlerLocatorPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    $this->locate($container, DddTags::COMMAND_HANDLER, 'tangible_ddd.middleware.command_handler');
    $this->locate($container, DddTags::QUERY_HANDLER, 'tangible_ddd.middleware.query_handler');

    if ($container->hasDefinition('tangible_ddd.middleware.self_executing')) {
      $refs = [];
      foreach ($container->getDefinitions() as $id => $definition) {
        if (self::isTypeId($id) && !$definition->isAbstract() && !$definition->isSynthetic()) {
          $refs[$id] = new Reference($id, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE);
        }
      }
      foreach ($container->getAliases() as $id => $alias) {
        if (self::isTypeId($id) && !isset($refs[$id])) {
          $refs[$id] = new Reference($id, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE);
        }
      }
      ksort($refs);
      $container->getDefinition('tangible_ddd.middleware.self_executing')
        ->replaceArgument(0, ServiceLocatorTagPass::register($container, $refs));
    }
  }

  private function locate(ContainerBuilder $container, string $tag, string $middlewareId): void {
    if (!$container->hasDefinition($middlewareId)) {
      return;
    }
    $refs = [];
    foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
      $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      $refs[is_string($class) ? $class : $id] = new Reference($id);
    }
    ksort($refs);
    $container->getDefinition($middlewareId)->replaceArgument(0, ServiceLocatorTagPass::register($container, $refs));
  }

  private static function isTypeId(string $id): bool {
    return str_contains($id, '\\') && !str_starts_with($id, '.') && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $id) === 1;
  }
}
