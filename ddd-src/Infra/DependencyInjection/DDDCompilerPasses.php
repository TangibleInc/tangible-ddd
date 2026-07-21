<?php

namespace TangibleDDD\Infra\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DDDCompilerPasses {

  public static function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new LongProcessCatalogPass());
  }
}
