<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\CommandHandlers\IReturningCommandHandler;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Queries\SelfHandlingQuery;
use TangibleDDD\Symfony\DependencyInjection\DddTags;

/**
 * Replaces the WordPress wiring's `@service_container` (E S2): in a Symfony
 * kernel application services are private, so Tactician's handler lookup and
 * SelfExecutingCommandMiddleware's handle() method injection get compiled
 * ServiceLocators instead.
 *
 * - `tangible_ddd.command_handlers` / `tangible_ddd.query_handlers`: tagged
 *   handlers keyed by their CLASS (what HandlerClassNameInflector returns).
 * - handle() dependency locator: like Symfony's controller argument locator,
 *   it holds exactly the parameter types of the handle() methods of the
 *   self-handling commands and queries it knows, read by reflection at
 *   compile time, plus EventsUnitOfWork (the middleware attaches it). Known
 *   = tagged DddTags::SELF_HANDLING (autoconfigured for classes the app's
 *   resource loading registers, e.g. `App\: resource: ../src/`) or listed in
 *   `tangible_ddd.self_handling.classes`. Every other private service stays
 *   removable. References are runtime-invalid, so a broken service fails
 *   only when a handle() asks for it.
 * - `tangible_ddd.self_handling.locate_all: true` restores the round-1
 *   behaviour (every class-named service and alias), for apps whose
 *   self-handling classes are neither loaded as services nor listed. It
 *   keeps every autowired service of the app in the compiled container.
 */
final class HandlerLocatorPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    $this->locate($container, DddTags::COMMAND_HANDLER, 'tangible_ddd.middleware.command_handler');
    $this->locate($container, DddTags::QUERY_HANDLER, 'tangible_ddd.middleware.query_handler');
    $others = self::other_consumers($container);
    foreach (['tangible_ddd.wake_target', ...array_map(static fn (string $n) => "tangible_ddd.consumer.$n.wake_target", $others)] as $target) {
      $this->locate($container, DddTags::CONTINUES_WORKFLOW, $target); // W1
    }

    if (!$container->hasDefinition('tangible_ddd.middleware.self_executing')) {
      return;
    }

    $config = $container->hasParameter('tangible_ddd.self_handling')
      ? (array) $container->getParameter('tangible_ddd.self_handling')
      : [];
    $types = ($config['locate_all'] ?? false)
      ? $this->everyTypeId($container)
      : $this->handleParameterTypes($container, $this->selfHandlingClasses($container, (array) ($config['classes'] ?? [])));

    if ($container->has(EventsUnitOfWork::class)) {
      $types[EventsUnitOfWork::class] = true;
    }

    $refs = [];
    foreach (array_keys($types) as $id) {
      $refs[$id] = new Reference($id, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE);
    }
    ksort($refs);
    $container->getDefinition('tangible_ddd.middleware.self_executing')
      ->replaceArgument(0, ServiceLocatorTagPass::register($container, $refs));

    // Wave 5: each other consumer's handle() locator has the same types, but a
    // type the bundle binds per consumer (a port alias) resolves to its own service.
    foreach ($others as $name) {
      $middleware = "tangible_ddd.consumer.$name.middleware.self_executing";
      if (!$container->hasDefinition($middleware)) {
        continue;
      }
      $own = [];
      foreach ($refs as $type => $ref) {
        $target = self::alias_target($container, (string) $ref);
        $mine = str_starts_with($target, 'tangible_ddd.') ? "tangible_ddd.consumer.$name." . substr($target, strlen('tangible_ddd.')) : null;
        $own[$type] = $mine !== null && $container->hasDefinition($mine)
          ? new Reference($mine, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE)
          : $ref;
      }
      $container->getDefinition($middleware)->replaceArgument(0, ServiceLocatorTagPass::register($container, $own));
    }
  }

  /** @return list<string> the names of the non-primary consumers (wave 5) */
  private static function other_consumers(ContainerBuilder $container): array {
    if (!$container->hasParameter('tangible_ddd.consumers')) {
      return [];
    }
    $names = [];
    foreach ((array) $container->getParameter('tangible_ddd.consumers') as $c) {
      if (!($c['primary'] ?? true)) {
        $names[] = (string) $c['name'];
      }
    }
    return $names;
  }

  private static function alias_target(ContainerBuilder $container, string $id): string {
    for ($i = 0; $i < 10 && $container->hasAlias($id); $i++) {
      $id = (string) $container->getAlias($id);
    }
    return $id;
  }

  /** @return list<class-string> */
  private function selfHandlingClasses(ContainerBuilder $container, array $listed): array {
    $classes = [];
    foreach ($container->findTaggedServiceIds(DddTags::SELF_HANDLING) as $id => $_) {
      $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (is_string($class)) {
        $classes[$class] = true;
      }
    }
    foreach ($listed as $class) {
      if (!is_string($class) || $container->getReflectionClass($class, false) === null) {
        throw new InvalidArgumentException(sprintf('tangible_ddd.self_handling.classes: class "%s" does not exist.', (string) $class));
      }
      if (!is_a($class, SelfHandlingCommand::class, true) && !is_a($class, SelfHandlingQuery::class, true)) {
        throw new InvalidArgumentException(sprintf(
          'tangible_ddd.self_handling.classes: "%s" is neither a SelfHandlingCommand nor a SelfHandlingQuery.', $class
        ));
      }
      $classes[$class] = true;
    }
    return array_keys($classes);
  }

  /**
   * @param list<class-string> $classes
   * @return array<string, true>
   */
  private function handleParameterTypes(ContainerBuilder $container, array $classes): array {
    $types = [];
    foreach ($classes as $class) {
      $reflection = $container->getReflectionClass($class, false); // also tracks the file for cache freshness
      if ($reflection === null || !$reflection->hasMethod('handle')) {
        continue; // the middleware reports SelfHandlingCommandHasNoHandler at dispatch
      }
      foreach ($reflection->getMethod('handle')->getParameters() as $param) {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
          continue; // default value or UnresolvableHandleDependency at dispatch, as the middleware decides
        }
        $name = $type->getName();
        if (in_array(strtolower($name), ['self', 'static'], true) || is_a($name, ICommandHandler::class, true) || is_a($name, IReturningCommandHandler::class, true)) {
          continue; // SelfHandlingCommandWrapsHandler at dispatch
        }
        $types[$name] = true;
      }
    }
    return $types;
  }

  /** @return array<string, true> */
  private function everyTypeId(ContainerBuilder $container): array {
    $types = [];
    foreach ($container->getDefinitions() as $id => $definition) {
      if (self::isTypeId($id) && !$definition->isAbstract() && !$definition->isSynthetic()
        && !$definition->hasTag(DddTags::SELF_HANDLING)) {
        $types[$id] = true;
      }
    }
    foreach ($container->getAliases() as $id => $_) {
      if (self::isTypeId($id)) {
        $types[$id] = true;
      }
    }
    return $types;
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
