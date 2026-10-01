<?php

namespace AcmeOrders\WordPress\DI;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;

$container_builder = new ContainerBuilder();

// Expose the plugin version to the container as a parameter so services.yaml
// can reference it via %acme_orders.version% instead of hardcoding 'dev'.
$container_builder->setParameter(
  'acme_orders.version',
  defined( 'ACME_ORDERS_VERSION' ) ? constant( 'ACME_ORDERS_VERSION' ) : 'dev'
);

$loader = new YamlFileLoader( $container_builder, new FileLocator( __DIR__ ) );
$loader->load( 'tactician.yaml' );
$loader->load( 'services.yaml' );
DDDCompilerPasses::register( $container_builder );

/**
 * Get the DI container.
 *
 * @param ContainerBuilder|null $container_instance Override container (for testing)
 * @return ContainerBuilder
 */
function di( ?ContainerBuilder $container_instance = null ): ContainerBuilder {
  static $container;

  // Allow override in tests
  if ( defined( 'DOING_TANGIBLE_TESTS' ) && $container_instance ) {
    $container = $container_instance;
  }

  return $container ?: ( $container = $container_instance );
}
di( $container_builder );

/**
 * Compile the container on WordPress init.
 */
function compile_container() {
  $container = di();

  do_action( 'acme_orders_pre_compile_di', $container );
  $container->compile();
  do_action( 'acme_orders_post_compile_di', $container );
}

add_action( 'init', __NAMESPACE__ . '\compile_container', 1 );

// The whole tangible-ddd handshake: announces this plugin to the consumer
// registry and defers register_hooks() to init:2 (after the compile above).
// Framework tables are created/healed by the migration lane on the first
// init tick — no activation hook needed. The generated main-plugin wrapper
// includes this file at plugins_loaded:10, after framework initialization.
\TangibleDDD\WordPress\boot(
  new \TangibleDDD\Infra\DDDConfig(
    prefix: 'acme_orders',
    namespace_root: 'AcmeOrders',
    version: defined( 'ACME_ORDERS_VERSION' ) ? constant( 'ACME_ORDERS_VERSION' ) : 'dev',
  ),
  static fn () => di()
);
