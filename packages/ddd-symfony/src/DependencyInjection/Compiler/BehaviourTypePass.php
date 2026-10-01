<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Symfony\DependencyInjection\DddTags;

/**
 * W2: the compile-time map of behaviour config types, parameter
 * `tangible_ddd.behaviour_types` (type => class, sorted by type), from the
 * BaseBehaviourConfig subclasses tagged DddTags::BEHAVIOUR_CONFIG (the app's
 * resource loading, autoconfigured) and those listed in
 * `tangible_ddd.workflow.behaviour_types`. Each type is read from
 * get_behaviour_type() on an instance made without its constructor. The
 * tagged definitions are values, not services, and are removed.
 *
 * Errors: a listed class that is not a concrete BaseBehaviourConfig, or two
 * classes claiming one type, fail compilation.
 */
final class BehaviourTypePass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    $classes = $container->hasParameter('tangible_ddd.behaviour_config_classes')
      ? (array) $container->getParameter('tangible_ddd.behaviour_config_classes')
      : [];
    foreach ($container->findTaggedServiceIds(DddTags::BEHAVIOUR_CONFIG) as $id => $_) {
      $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (is_string($class)) {
        $classes[] = $class;
      }
      $container->removeDefinition($id);
    }

    $types = [];
    foreach (array_unique($classes) as $class) {
      $reflection = $container->getReflectionClass($class, false);
      if ($reflection === null || !$reflection->isSubclassOf(BaseBehaviourConfig::class)) {
        throw new InvalidArgumentException(sprintf('tangible_ddd.workflow.behaviour_types: "%s" is not a BaseBehaviourConfig subclass.', $class));
      }
      if ($reflection->isAbstract()) {
        continue;
      }
      try {
        $type = $reflection->newInstanceWithoutConstructor()->get_behaviour_type();
      } catch (\Throwable $e) {
        throw new InvalidArgumentException(sprintf('%s::get_behaviour_type() must return a constant (it is read without the constructor): %s', $class, $e->getMessage()), 0, $e);
      }
      if (isset($types[$type]) && $types[$type] !== $class) {
        throw new InvalidArgumentException(sprintf('Behaviour type "%s" is claimed by both %s and %s.', $type, $types[$type], $class));
      }
      $types[$type] = $class;
    }
    ksort($types);
    $container->setParameter('tangible_ddd.behaviour_types', $types);
  }
}
