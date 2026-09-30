<?php

namespace TangibleDDD\Infra\DependencyInjection;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\LongProcessCatalog;

final class LongProcessCatalogPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    $entries = [];

    foreach ($container->findTaggedServiceIds('ddd.long_process') as $id => $tags) {
      $definition = $container->getDefinition($id);
      $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

      if (!is_string($class) || !is_subclass_of($class, LongProcess::class)) {
        $display_class = is_string($class) ? $class : $id;
        throw new InvalidArgumentException(
          "$display_class is tagged ddd.long_process but does not extend LongProcess",
        );
      }

      $entries[$class] = [
        ...($entries[$class] ?? []),
        ...$tags,
      ];
    }

    $container->register(LongProcessCatalog::class, LongProcessCatalog::class)
      ->setArguments([$entries])
      ->setPublic(true);
  }
}
