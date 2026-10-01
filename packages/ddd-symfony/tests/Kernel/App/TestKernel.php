<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TangibleDDD\Symfony\Bundle\TangibleDddBundle;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * The kernel test app: FrameworkBundle + DoctrineBundle + TangibleDddBundle
 * on the test Postgres database, private-by-default autowired app services
 * (so the handler locators are exercised), Messenger on doctrine://default.
 *
 * $variant selects extra configuration for negative compile tests.
 */
final class TestKernel extends Kernel {
  use MicroKernelTrait;

  public function __construct(string $environment = 'test', bool $debug = true, private readonly string $variant = 'default') {
    parent::__construct($environment, $debug);
  }

  public function registerBundles(): iterable {
    yield new FrameworkBundle();
    yield new DoctrineBundle();
    yield new TangibleDddBundle();
  }

  public function getProjectDir(): string {
    return \dirname(__DIR__, 3);
  }

  public function getCacheDir(): string {
    return $this->getProjectDir() . '/var/cache/' . $this->environment . '-' . $this->variant;
  }

  public function getLogDir(): string {
    return $this->getProjectDir() . '/var/log';
  }

  /** Keeps FrameworkBundle's generated config/reference.php out of the package's own config/. */
  private function getConfigDir(): string {
    return $this->getProjectDir() . '/var/test-config';
  }

  protected function configureRoutes(RoutingConfigurator $routes): void {}

  protected function configureContainer(ContainerConfigurator $container): void {
    $container->extension('framework', [
      'test' => true,
      'secret' => 'ddd-symfony-kernel-test',
      'http_method_override' => false,
      'handle_all_throwables' => true,
      'php_errors' => ['log' => true],
      'messenger' => [
        'default_bus' => 'messenger.bus.default',
        'buses' => ['messenger.bus.default' => $this->variant === 'doctrine_transaction'
          ? ['middleware' => ['doctrine_transaction']]
          : []],
      ],
    ]);

    $params = PostgresDatabase::params();
    $container->extension('doctrine', [
      'dbal' => [
        'driver' => 'pdo_pgsql',
        'host' => $params['host'],
        // inband_pooled: a PgBouncer-style port; the boot check reads params and never connects.
        'port' => $this->variant === 'inband_pooled' ? 6432 : $params['port'],
        'user' => $params['user'],
        'password' => $params['password'],
        'dbname' => $params['dbname'],
        'server_version' => '16',
      ],
    ]);

    $container->extension('tangible_ddd', [
      'consumer' => [
        'prefix' => 'sfk',
        'namespace_root' => __NAMESPACE__,
        'version' => '0.7.0-test',
      ],
      'connection' => 'default',
      'transaction' => $this->variant === 'flush' ? ['entity_manager' => 'test.flusher'] : [],
      'process' => in_array($this->variant, ['inband_pooled', 'inband'], true) ? ['inband_start' => true] : [],
    ]);

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();
    $services->set('logger', NullLogger::class);
    $services->set('test.flusher', RecordingFlusher::class)->public();
    $services->load(__NAMESPACE__ . '\\', __DIR__ . '/{Commands,CommandHandlers,Events,Listeners,Persistence,Reactions}/')
      // Commands are resource-loaded like `App\: resource: ../src/` does in an app:
      // autoconfiguration tags the self-handling ones for the handle() locator.
      ->exclude(__DIR__ . '/Events/');
  }
}
