<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TangibleDDD\Runtime\FrozenClock;
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

  /** frozen_clock: the bundle's clock is a FrozenClock (public), so a test can move time (alarms, backoff). */
  protected function build(ContainerBuilder $container): void {
    if ($this->variant !== 'frozen_clock') {
      return;
    }
    $container->addCompilerPass(new class implements CompilerPassInterface {
      public function process(ContainerBuilder $container): void {
        $container->getDefinition('tangible_ddd.clock')->setClass(FrozenClock::class)->setArguments([])->setPublic(true);
      }
    });
  }

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
    $orm = $this->variant === 'orm' ? ['orm' => [
      'controller_resolver' => ['auto_mapping' => false],
      'mappings' => ['KernelApp' => [
        'type' => 'attribute',
        'is_bundle' => false,
        'dir' => __DIR__ . '/Orm/Entity',
        'prefix' => __NAMESPACE__ . '\\Orm\\Entity',
      ]],
    ]] : [];
    $container->extension('doctrine', $orm + [
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

    // multi: two consumers (wave 5), the app and the Billing context in Postgres schema `billing`.
    $consumers = $this->variant === 'multi' ? ['consumers' => [
      'sfk' => ['namespace_root' => __NAMESPACE__, 'version' => '0.7.0-test'],
      'bil' => [
        'namespace_root' => 'TangibleDDD\\Symfony\\Tests\\Kernel\\Billing',
        'schema' => 'billing',
        'transport' => 'ddd_facts_bil',
        'wakeup_transport' => 'ddd_wakeups_bil',
        'delivery' => ['budget' => 2, 'retry_delay_ms' => 0],
      ],
    ]] : ['consumer' => [
      'prefix' => 'sfk',
      'namespace_root' => __NAMESPACE__,
      // version_env: the README's env form, with the variable unset (resolves to null, L4).
      'version' => $this->variant === 'version_env' ? '%env(default::DDD_SF_TEST_UNSET_VERSION)%' : '0.7.0-test',
    ]];
    $container->extension('tangible_ddd', $consumers + [
      'connection' => 'default',
      'transaction' => match ($this->variant) {
        'flush' => ['entity_manager' => 'test.flusher'],
        'orm' => ['entity_manager' => 'doctrine.orm.default_entity_manager'],
        default => [],
      },
      'process' => in_array($this->variant, ['inband_pooled', 'inband'], true) ? ['inband_start' => true] : [],
      // no_listen: D14 off (no NOTIFY, ddd:relay polls), the "NOTIFY suppressed" case.
      'relay' => $this->variant === 'no_listen' ? ['listen' => false] : [],
      // workflow_settings: W3, the igniter clocks and the facts transport's redeliver_timeout.
      'workflow' => $this->variant === 'workflow_settings' ? ['stale_start_seconds' => 120, 'stale_claim_seconds' => 300] : [],
      'messenger' => $this->variant === 'workflow_settings' ? ['redeliver_timeout_seconds' => 240] : [],
      // audit: D12 lists next to #[Audit] (AttributeAuditPolicy), into a readable sink.
      'audit' => $this->variant === 'audit' ? [
        'sink' => 'test.audit_sink',
        'not_audited' => [Commands\RenameWidgetCommand::class],
        'without_parameters' => [Commands\ProgressCommand::class],
      ] : [],
    ]);

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();
    $services->set('logger', NullLogger::class);
    $services->set('test.flusher', RecordingFlusher::class)->public();
    $services->set('test.audit_sink', \TangibleDDD\Testing\InMemoryAuditSink::class)->autowire(false)->public();
    // An app service that injects the D10 stores (unused private services are removed).
    $services->set('test.d10_stores', \ArrayObject::class)->args([[
      \Symfony\Component\DependencyInjection\Loader\Configurator\service(\TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository::class),
      \Symfony\Component\DependencyInjection\Loader\Configurator\service(\TangibleDDD\Domain\Repositories\IWorkItemRepository::class),
      \Symfony\Component\DependencyInjection\Loader\Configurator\service(\TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger::class),
    ]])->public();
    $services->alias('test.effect_journal', \TangibleDDD\Runtime\Effects\IEffectJournal::class)->public();
    $services->alias('test.operator_view', \TangibleDDD\Runtime\Ops\IOperatorView::class)->public();
    $services->alias('test.workflow_ledger', \TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger::class)->public();
    $services->alias('test.workflow_igniter', 'tangible_ddd.workflow_igniter')->public();
    $services->alias('test.subscriptions', 'tangible_ddd.subscriptions')->public();
    $services->alias('test.workflow_continuations', 'tangible_ddd.workflow_continuations')->public();
    $services->alias('test.chunked_digest', Workflows\ChunkedDigestWorkflow::class)->public();
    $services->alias('test.effect_middleware', 'tangible_ddd.middleware.effect')->public();
    $services->alias('test.process_lock', \TangibleDDD\Runtime\Lock\IProcessLock::class)->public();
    $services->load(__NAMESPACE__ . '\\', __DIR__ . '/{Commands,CommandHandlers,Events,Listeners,Persistence,Process,Reactions,Workflows}/')
      // Commands are resource-loaded like `App\: resource: ../src/` does in an app:
      // autoconfiguration tags the self-handling ones for the handle() locator.
      ->exclude(__DIR__ . '/Events/');
    if ($this->variant === 'orm') {
      $services->load(__NAMESPACE__ . '\\Orm\\', __DIR__ . '/Orm/{Commands,CommandHandlers}/');
    }
    if ($this->variant === 'multi') {
      $services->load('TangibleDDD\\Symfony\\Tests\\Kernel\\Billing\\', \dirname(__DIR__) . '/Billing/{Commands,CommandHandlers,Listeners,Process}/');
    }
  }
}
