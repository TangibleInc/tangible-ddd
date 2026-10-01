<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use League\Tactician\CommandBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * The framework's consumer template container, packages/ddd-wp/wordpress/di/
 * (report A F-28, B26). tactician.yaml used to name classes that do not exist
 * (`...Mapping\MapByNamingConvention\MapByNamingConvention`,
 * `...MapByNamingConvention\MethodName\Handle`,
 * `TangibleDDD\WordPress\DI\HandlerClassNameInflector`) and was only ever
 * YAML-parsed. This test compiles services.yaml + tactician.yaml and builds
 * both buses.
 */
final class FrameworkTacticianYamlTest extends TestCase {

  private const DIR = __DIR__ . '/../../../packages/ddd-wp/wordpress/di';

  private mixed $previousWpdb = null;

  protected function setUp(): void {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    $GLOBALS['wpdb'] = $this->previousWpdb;
  }

  public function test_every_class_the_tactician_yaml_names_exists(): void {
    foreach (Yaml::parseFile(self::DIR . '/tactician.yaml')['services'] as $id => $definition) {
      $class = is_array($definition) && isset($definition['class']) ? $definition['class'] : $id;
      if ($id === '_defaults') {
        continue;
      }
      $this->assertTrue(class_exists($class), "{$id} names {$class}, which does not exist");
    }
  }

  public function test_the_template_container_compiles_and_builds_both_buses(): void {
    $builder = new ContainerBuilder();
    $builder->register(IDDDConfig::class, FakeDDDConfig::class)->setPublic(true);

    $loader = new YamlFileLoader($builder, new FileLocator(self::DIR));
    $loader->load('services.yaml');
    $loader->load('tactician.yaml');
    $builder->compile();

    $this->assertInstanceOf(CommandBus::class, $builder->get(CommandBus::class));
    $this->assertInstanceOf(CommandBus::class, $builder->get('tactician.query_bus'));
  }
}
