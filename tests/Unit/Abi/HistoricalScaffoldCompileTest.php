<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi;

use League\Tactician\CommandBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;

/**
 * ABI freeze, register B9 (section 8, wave 2 wp; 7.1 "Historical scaffold
 * YAML"): the DI files `wp ddd init` generated at every in-window tag
 * compile against N and every public service resolves.
 *
 * Consumers' scaffolded services.yaml / tactician.yaml name framework
 * classes as service ids, autowire their constructors (`~`) or hand-list
 * positional arguments, and compile at init:1 on every request. A renamed
 * class, a new required constructor parameter or a changed factory fatals
 * the site; the templates themselves are dead code to the type system.
 *
 * Fixtures: fixtures/scaffold/<dir>/ is the scaffolder output of
 * `wp ddd init acme_orders AcmeOrders ACME_ORDERS_VERSION` at each tag
 * (bin/generate-fixtures.php; manifest.json maps v0.6.3..v0.6.6 onto the
 * identical v0.6.2 output). The build follows that tag's di/index.php:
 * the version parameter, tactician.yaml then services.yaml, and
 * DDDCompilerPasses::register() where the index calls it (v0.6.2 onwards);
 * then compile, as compile_container() does at init:1.
 */
final class HistoricalScaffoldCompileTest extends TestCase {

  private mixed $previousWpdb = null;

  /** @var list<string> */
  private static array $workDirs = [];

  public static function tearDownAfterClass(): void {
    foreach (self::$workDirs as $work) {
      $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
      }
      @rmdir($work);
    }
    self::$workDirs = [];
  }

  protected function setUp(): void {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new \wpdb();
    // ddd-wp init as the winner runs it at plugins_loaded:1, before any
    // consumer container compiles (OutboxConfig::from_options needs its
    // options reader, X11).
    HostDefaultsWiring::register();
  }

  protected function tearDown(): void {
    $GLOBALS['wpdb'] = $this->previousWpdb;
  }

  /** @return array{args: list<string>, tags: array<string, array{dir: string, scaffolder_blob: string}>} */
  private static function manifest(): array {
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/scaffold/manifest.json'), true);
  }

  /** @return iterable<string, array{string, string}> */
  public static function scaffolds(): iterable {
    foreach (self::manifest()['tags'] as $tag => $entry) {
      yield $tag => [$tag, __DIR__ . '/fixtures/scaffold/' . $entry['dir']];
    }
  }

  public function test_the_matrix_covers_every_in_window_tag(): void {
    self::assertSame(['v0.6.0', 'v0.6.2', 'v0.6.3', 'v0.6.4', 'v0.6.5', 'v0.6.6'], array_keys(self::manifest()['tags']), 'register 7.1: v0.6.0 and v0.6.2..v0.6.6 (no v0.6.1 tag exists)');
  }

  #[DataProvider('scaffolds')]
  public function test_the_scaffolded_container_compiles_and_every_public_service_resolves(string $tag, string $dir): void {
    [$prefix] = self::manifest()['args'];
    $builder = $this->build($dir, $prefix);

    $failures = [];
    $resolved = 0;
    foreach ($builder->getDefinitions() as $id => $definition) {
      if (!$definition->isPublic() || $definition->isAbstract() || $definition->isSynthetic() || $id === 'service_container') {
        continue;
      }
      try {
        $builder->get($id);
        $resolved++;
      } catch (\Throwable $e) {
        $failures[] = sprintf('%s: %s: %s', $id, get_class($e), $e->getMessage());
      }
    }

    self::assertSame([], $failures, "$tag scaffold: public services that no longer resolve against N:\n" . implode("\n", $failures));
    self::assertGreaterThanOrEqual(15, $resolved, "$tag scaffold: the framework services were resolved");
  }

  #[DataProvider('scaffolds')]
  public function test_the_scaffolded_wiring_builds_the_runtime_a_consumer_relies_on(string $tag, string $dir): void {
    [$prefix] = self::manifest()['args'];
    $builder = $this->build($dir, $prefix);

    $config = $builder->get(IDDDConfig::class);
    self::assertSame($prefix, $config->prefix(), "$tag: the consumer identity");
    self::assertInstanceOf(CommandBus::class, $builder->get(CommandBus::class), "$tag: command bus");
    self::assertInstanceOf(CommandBus::class, $builder->get('tactician.query_bus'), "$tag: query bus");
    self::assertInstanceOf(TransactionMiddleware::class, $builder->get(TransactionMiddleware::class));
    self::assertInstanceOf(ProcessRunner::class, $builder->get(ProcessRunner::class), "$tag: ProcessRunner autowired with its 0.6 two arguments; the wave-2 ports stay at their null defaults");
    self::assertInstanceOf(OutboxProcessor::class, $builder->get(OutboxProcessor::class));
    self::assertSame("$prefix-outbox", $builder->get(OutboxConfig::class)->action_scheduler_group, "$tag: OutboxConfig::from_options factory");
  }

  /**
   * The scaffold plus the smallest consumer a scaffold grows into: one
   * transactional command whose handler (found by the scaffold's resource
   * glob and the naming convention) raises an announcing domain event and
   * returns a value. Dispatched through the scaffolded command bus, it runs
   * the act bracket, the 0.6 TransactionMiddleware, event publication into
   * the 0.6 outbox repository (wpdb stub) and returns the value (D11).
   */
  #[DataProvider('scaffolds')]
  public function test_a_command_runs_through_the_scaffolded_bus(string $tag, string $dir): void {
    [$prefix] = self::manifest()['args'];
    $work = self::withConsumerClasses($dir);
    $builder = $this->build($work, $prefix);

    $result = $builder->get(CommandBus::class)->handle(new \AcmeOrders\Application\Commands\PlaceOrderCommand('o-1'));

    self::assertSame('placed:o-1', $result, "$tag: the handler's return value passes through the scaffolded middleware");
    self::assertNotNull(\TangibleDDD\Application\Events\PublishedFacts::id_of(\AcmeOrders\Application\CommandHandlers\PlaceOrderHandler::$last), "$tag: the announced fact went through the scaffolded outbox bus");
  }

  /** A temp copy of the scaffold with the consumer classes; AcmeOrders\ autoloads from the first copy. */
  private static function withConsumerClasses(string $dir): string {
    $work = sys_get_temp_dir() . '/ddd-abi-scaffold-' . getmypid() . '-' . basename($dir);
    if (!is_dir($work)) {
      $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
      foreach ($it as $item) {
        $target = $work . '/' . substr($item->getPathname(), strlen($dir) + 1);
        if ($item->isDir()) {
          @mkdir($target, 0777, true);
        } else {
          @mkdir(dirname($target), 0777, true);
          copy($item->getPathname(), $target);
        }
      }
      self::$workDirs[] = $work;
      @mkdir("$work/ddd-src/Application/Commands", 0777, true);
      @mkdir("$work/ddd-src/Application/CommandHandlers", 0777, true);
      @mkdir("$work/ddd-src/Domain/Events", 0777, true);
      file_put_contents("$work/ddd-src/Application/Commands/PlaceOrderCommand.php", <<<'PHP'
        <?php
        namespace AcmeOrders\Application\Commands;
        final class PlaceOrderCommand implements \TangibleDDD\Application\Commands\ITransactionalCommand {
          public function __construct(public readonly string $order_id) {}
        }
        PHP);
      file_put_contents("$work/ddd-src/Domain/Events/OrderPlaced.php", <<<'PHP'
        <?php
        namespace AcmeOrders\Domain\Events;
        final class OrderPlaced extends \TangibleDDD\Domain\Events\DomainEvent implements \TangibleDDD\Domain\Events\IAnnouncesIntegration, \TangibleDDD\Domain\Events\IIntegrationEvent {
          use \TangibleDDD\Domain\Events\IntegrationBehaviour;
          public function __construct(public readonly string $order_id) {}
          public function payload(): array { return $this->integration_payload(); }
          protected static function prefix(): string { return 'acme_orders'; }
        }
        PHP);
      file_put_contents("$work/ddd-src/Application/CommandHandlers/PlaceOrderHandler.php", <<<'PHP'
        <?php
        namespace AcmeOrders\Application\CommandHandlers;
        use AcmeOrders\Application\Commands\PlaceOrderCommand;
        use AcmeOrders\Domain\Events\OrderPlaced;
        use TangibleDDD\Application\Events\EventsUnitOfWork;
        final class PlaceOrderHandler {
          public static ?OrderPlaced $last = null;
          public function __construct(private readonly EventsUnitOfWork $events) {}
          public function handle(PlaceOrderCommand $command): string {
            $this->events->record(self::$last = new OrderPlaced($command->order_id));
            return 'placed:' . $command->order_id;
          }
        }
        PHP);
    }
    static $autoload = false;
    if (!$autoload) {
      $autoload = true;
      $src = "$work/ddd-src/";
      spl_autoload_register(static function (string $class) use ($src): void {
        if (str_starts_with($class, 'AcmeOrders\\')) {
          $file = $src . str_replace('\\', '/', substr($class, strlen('AcmeOrders\\'))) . '.php';
          if (is_file($file)) {
            require $file;
          }
        }
      });
    }
    return $work;
  }

  private function build(string $dir, string $prefix): ContainerBuilder {
    $index = (string) file_get_contents("$dir/ddd-wordpress/di/index.php");

    $builder = new ContainerBuilder();
    $builder->setParameter("$prefix.version", 'dev');
    $loader = new YamlFileLoader($builder, new FileLocator("$dir/ddd-wordpress/di"));
    $loader->load('tactician.yaml');
    $loader->load('services.yaml');
    if (str_contains($index, 'DDDCompilerPasses::register(')) {
      DDDCompilerPasses::register($builder);
    }
    $builder->compile();

    return $builder;
  }
}
