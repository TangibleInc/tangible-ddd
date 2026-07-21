<?php

namespace TangibleDDD\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class DumpedLongProcessCatalogTest extends TestCase {

  protected function setUp(): void {
    global $_test_actions, $_test_filters;
    $_test_actions = [];
    $_test_filters = [];
    DumpedRequiredConstructorProcess::$constructions = 0;

    $GLOBALS['wpdb'] = new class extends \wpdb {
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return 'wp_dump_catalog_long_processes';
      }
    };
  }

  public function test_dumped_container_registers_process_hooks_from_constructor_data(): void {
    global $_test_actions;

    $builder = new ContainerBuilder();
    $builder->register(DumpedCatalogConfig::class)
      ->setPublic(false);
    $builder->register(FakeProcessRepository::class)
      ->setPublic(false);
    $builder->setAlias(IDDDConfig::class, DumpedCatalogConfig::class);
    $builder->setAlias(IProcessRepository::class, FakeProcessRepository::class);
    $builder->register(ProcessRunner::class)
      ->setArguments([
        new Reference(IDDDConfig::class),
        new Reference(IProcessRepository::class),
      ])
      ->setPublic(true);
    $builder->register(DumpedRequiredConstructorProcess::class)
      ->setAutowired(false)
      ->setPublic(false)
      ->addTag('ddd.long_process', [
        'awaits' => [FakeIntegrationEvent::class],
      ]);

    DDDCompilerPasses::register($builder);
    $builder->compile();

    $suffix = bin2hex(random_bytes(8));
    $namespace = 'TangibleDDD\\Tests\\Generated\\Catalog' . $suffix;
    $class = 'DumpedContainer';
    $file = tempnam(sys_get_temp_dir(), 'ddd-catalog-');
    self::assertNotFalse($file);

    try {
      $code = (new PhpDumper($builder))->dump([
        'class' => $class,
        'namespace' => $namespace,
      ]);
      self::assertIsString($code);
      self::assertNotFalse(file_put_contents($file, $code));
      require $file;

      $runtime_class = $namespace . '\\' . $class;
      $runtime_container = new $runtime_class();

      self::assertFalse(method_exists($runtime_container, 'findTaggedServiceIds'));
      self::assertTrue($runtime_container->has(LongProcessCatalog::class));
      self::assertSame(
        [DumpedRequiredConstructorProcess::class => [[
          'awaits' => [FakeIntegrationEvent::class],
        ]]],
        $runtime_container->get(LongProcessCatalog::class)->all(),
      );
      self::assertSame(0, DumpedRequiredConstructorProcess::$constructions);

      \TangibleDDD\WordPress\register_hooks(
        new DumpedCatalogConfig(),
        static fn () => $runtime_container,
      );

      self::assertNotEmpty(
        $_test_actions[FakeIntegrationEvent::integration_action()] ?? [],
        'the dumped catalog must register the process #[Awaits] hook',
      );
      self::assertNotEmpty(
        $_test_actions[FakeResolvedEvent::integration_action()] ?? [],
        'the dumped catalog must register the process #[StartsOn] hook',
      );
      self::assertSame(0, DumpedRequiredConstructorProcess::$constructions);
    } finally {
      if (is_string($file) && file_exists($file)) {
        unlink($file);
      }
    }
  }
}

final class DumpedCatalogConfig implements IDDDConfig {
  public function prefix(): string { return 'dump_catalog'; }
  public function table(string $name): string { return 'wp_dump_catalog_' . $name; }
  public function hook(string $name): string { return 'dump_catalog_' . $name; }
  public function as_group(string $name): string { return 'dump-catalog-' . $name; }
  public function option(string $name): string { return 'dump_catalog_' . $name; }
  public function domain_action(string $event_name): string { return 'dump_catalog_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return 'dump_catalog_integration_' . $event_name; }
  public function version(): string { return 'dump-catalog'; }
}

#[Awaits(FakeIntegrationEvent::class)]
#[StartsOn(FakeResolvedEvent::class)]
final class DumpedRequiredConstructorProcess extends LongProcess {

  public static int $constructions = 0;

  public function __construct(public readonly int $account_id) {
    ++self::$constructions;
    parent::__construct(null);
  }

  public static function from_event(FakeResolvedEvent $event): ?static {
    return null;
  }
}
