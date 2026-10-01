<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use League\Tactician\CommandBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;
use TangibleDDD\Application\CommandHandlers\DiscardDeadLetterHandler;
use TangibleDDD\Application\CommandHandlers\PurgeOutboxHandler;
use TangibleDDD\Application\CommandHandlers\ReplayDeadLetterHandler;
use TangibleDDD\Application\CommandHandlers\RetryDeliveryHandler;

/**
 * The framework's own self-consumer container (packages/ddd-wp/wordpress/self/).
 *
 * B6: services.yaml used to register the operational handlers by a relative
 * resource directory into ddd-src/, which broke the moment the handlers moved
 * package. It now lists them explicitly, and this test compiles the real
 * YAML pair so a stale class or path fails here, not on a live site at
 * plugins_loaded:20.
 */
final class SelfConsumerContainerTest extends TestCase {

  private const DIR = __DIR__ . '/../../../packages/ddd-wp/wordpress/self';

  /** @return array<string, array{class-string}> */
  public static function handlers(): array {
    $out = [];
    foreach ([DiscardDeadLetterHandler::class, PurgeOutboxHandler::class, ReplayDeadLetterHandler::class, RetryDeliveryHandler::class] as $class) {
      $out[substr($class, strrpos($class, '\\') + 1)] = [$class];
    }
    return $out;
  }

  private static function compiled(): ContainerBuilder {
    $builder = new ContainerBuilder();
    $loader = new YamlFileLoader($builder, new FileLocator(self::DIR));
    $loader->load('tactician.yaml');
    $loader->load('services.yaml');
    $builder->compile();
    return $builder;
  }

  public function test_services_yaml_names_no_resource_directory(): void {
    $services = Yaml::parseFile(self::DIR . '/services.yaml')['services'];
    foreach ($services as $id => $definition) {
      $this->assertFalse(
        is_array($definition) && isset($definition['resource']),
        "{$id} registers by resource directory; list the handlers explicitly (B6)"
      );
    }
  }

  public function test_the_list_covers_every_framework_command_handler(): void {
    $found = [];
    foreach (['packages/ddd-core/src', 'packages/ddd-wp/src'] as $src) {
      foreach (glob(__DIR__ . '/../../../' . $src . '/Application/CommandHandlers/*.php') as $file) {
        $class = 'TangibleDDD\\Application\\CommandHandlers\\' . basename($file, '.php');
        if (!(new \ReflectionClass($class))->isInterface()) {
          $found[] = $class;
        }
      }
    }
    sort($found);

    $listed = array_column(self::handlers(), 0);
    sort($listed);
    $this->assertSame($found, $listed);
  }

  #[DataProvider('handlers')]
  public function test_the_self_container_compiles_with_each_operational_handler(string $class): void {
    $container = self::compiled();

    $this->assertTrue($container->has($class), "{$class} is registered");
    $definition = $container->getDefinition($class);
    $this->assertTrue($definition->isPublic(), 'the naming-convention resolver fetches handlers from the container');
    $this->assertFalse($definition->isShared(), 'handlers stay unshared, as the resource block had them');
  }

  public function test_the_self_container_defines_the_command_bus(): void {
    $container = self::compiled();

    $this->assertTrue($container->has(CommandBus::class));
    $this->assertSame(CommandBus::class, $container->getDefinition(CommandBus::class)->getClass() ?? CommandBus::class);
  }
}
