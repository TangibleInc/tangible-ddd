<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Intents\IntentsMiddleware;
use TangibleDDD\Application\Persistence\TransactionMiddleware;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * Pins IntentsMiddleware's slot on the command buses the framework ships,
 * read from the COMPILED containers: immediately after
 * TransactionMiddleware, so the act scope is open and the end-of-act check
 * runs inside the transaction, and before DomainEventsPublishMiddleware, so
 * synchronous domain-event handlers have run by then. Never on the query
 * bus: queries are not acts.
 *
 * The consumer template (di/) is compiled with a fake IDDDConfig, the one
 * binding a consumer must provide. The self-consumer (self/) is loaded
 * exactly as self/index.php loads it; its services.yaml imports
 * di/services.yaml for the middleware's registration.
 */
class IntentsMiddlewareWiringTest extends TestCase {

  /** @return array<string, array{ContainerBuilder}> */
  public static function containers(): array {
    $root = dirname(__DIR__, 3) . '/ddd-wordpress';

    $template = self::load($root . '/di');
    $template->register(IDDDConfig::class, FakeDDDConfig::class)->setPublic(true);
    $template->compile();

    require_once $root . '/self/HandlerClassNameInflector.php';
    $self = self::load($root . '/self');
    $self->compile();

    return [
      'di/ (consumer template)' => [$template],
      'self/ (self-consumer)' => [$self],
    ];
  }

  #[DataProvider('containers')]
  public function test_command_bus_runs_intents_between_transaction_and_event_publishing(ContainerBuilder $container): void {
    $this->assertSame(
      [
        CorrelationMiddleware::class,
        TransactionMiddleware::class,
        IntentsMiddleware::class,
        DomainEventsPublishMiddleware::class,
        SelfExecutingCommandMiddleware::class,
        \League\Tactician\Handler\CommandHandlerMiddleware::class,
      ],
      $this->chain($container, \League\Tactician\CommandBus::class)
    );
  }

  #[DataProvider('containers')]
  public function test_query_bus_does_not_carry_the_middleware(ContainerBuilder $container): void {
    $this->assertNotContains(IntentsMiddleware::class, $this->chain($container, 'tactician.query_bus'));
  }

  private static function load(string $dir): ContainerBuilder {
    $container = new ContainerBuilder();
    $loader = new YamlFileLoader($container, new FileLocator($dir));
    $loader->load('tactician.yaml');
    $loader->load('services.yaml');
    return $container;
  }

  /**
   * The middleware classes of a compiled bus, in order. Compilation may
   * inline a private middleware as a Definition or keep it as a Reference.
   *
   * @return list<string>
   */
  private function chain(ContainerBuilder $container, string $bus): array {
    return array_map(
      fn($argument) => match( true ) {
        $argument instanceof Reference => $container->findDefinition((string) $argument)->getClass(),
        $argument instanceof Definition => $argument->getClass(),
      },
      $container->getDefinition($bus)->getArguments()
    );
  }
}
