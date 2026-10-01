<?php

namespace TangibleDDD\Infra\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DDDCompilerPasses {

  public static function register(ContainerBuilder $container): void {
    // Child definitions and parameterized classes have their effective class
    // only after Symfony's optimization passes, while private discovery
    // definitions still exist until the removing phase.
    $container->addCompilerPass(
      new LongProcessCatalogPass(),
      PassConfig::TYPE_BEFORE_REMOVING,
    );
  }
}
