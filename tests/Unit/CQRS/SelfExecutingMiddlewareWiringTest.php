<?php

namespace TangibleDDD\Tests\Unit\CQRS;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TangibleDDD\WordPress\CLI\DDD_Command;

/**
 * Pins the SelfExecutingCommandMiddleware's slot in every bus chain the
 * framework ships (the yaml is dead code to the type system — this is the
 * same drift class ScaffoldTemplatesConformanceTest exists for):
 *
 *  - COMMAND bus: immediately BEFORE tactician.middleware.command_handler,
 *    so a self-handling command still gets the act bracket, transaction,
 *    and domain-event publishing before the short-circuit.
 *  - QUERY bus: immediately BEFORE tactician.middleware.query_handler —
 *    and NOTHING else in the chain. The query bus deliberately has no
 *    CorrelationMiddleware (queries are reads, not moments).
 *
 * Covered surfaces: ddd-wordpress/di/tactician.yaml (consumer template
 * container), ddd-wordpress/self/tactician.yaml (the framework's own
 * self-consumer), and the scaffolder-emitted template in
 * ddd-wordpress/cli/class-ddd-command.php.
 */
class SelfExecutingMiddlewareWiringTest extends TestCase {

  private const MIDDLEWARE = '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware';

  /** @return array<string, array{services: array<string, mixed>}> */
  public static function chain_sources(): array {
    $root = dirname(__DIR__, 3);

    require_once $root . '/ddd-wordpress/cli/class-ddd-command.php';
    $command = (new \ReflectionClass(DDD_Command::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(DDD_Command::class, 'get_templates');
    $method->setAccessible(true);
    $templates = $method->invoke($command, 'acme_orders', 'AcmeOrders', 'ACME_ORDERS_VERSION');

    return [
      'di/tactician.yaml' => [Yaml::parseFile($root . '/ddd-wordpress/di/tactician.yaml')['services']],
      'self/tactician.yaml' => [Yaml::parseFile($root . '/ddd-wordpress/self/tactician.yaml')['services']],
      'scaffolder template' => [Yaml::parse($templates['ddd-wordpress/di/tactician.yaml'])['services']],
    ];
  }

  #[DataProvider('chain_sources')]
  public function test_command_chain_has_the_middleware_immediately_before_the_handler_resolver(array $services): void {
    $chain = $services['League\Tactician\CommandBus']['arguments'];

    $slot = array_search(self::MIDDLEWARE, $chain, true);
    $this->assertNotFalse($slot, 'the command chain carries the middleware');
    $this->assertSame(
      '@tactician.middleware.command_handler',
      $chain[$slot + 1],
      'the middleware sits immediately before the naming-convention resolver'
    );
  }

  #[DataProvider('chain_sources')]
  public function test_query_chain_is_exactly_middleware_then_query_handler(array $services): void {
    $this->assertArrayHasKey('tactician.query_bus', $services, 'a query bus is wired');

    $this->assertSame(
      [
        self::MIDDLEWARE,
        '@tactician.middleware.query_handler',
      ],
      $services['tactician.query_bus']['arguments'],
      'query chain = self-executing middleware, then the resolver — and '
      . 'nothing else (deliberately no CorrelationMiddleware: reads, not moments)'
    );
  }

  #[DataProvider('chain_sources')]
  public function test_the_middleware_service_is_registered_with_the_container_argument(array $services): void {
    $this->assertSame(
      ['@service_container'],
      $services[ltrim(self::MIDDLEWARE, '@')]['arguments'],
      'explicit @service_container — the middleware is not reliably autowired by type'
    );
  }
}
