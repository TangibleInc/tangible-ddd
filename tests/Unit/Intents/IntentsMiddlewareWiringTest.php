<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins IntentsMiddleware's slot on the command buses the framework ships:
 * immediately after TransactionMiddleware, so the act scope is open and the
 * end-of-act check runs inside the transaction, and before
 * DomainEventsPublishMiddleware, so synchronous domain-event handlers have
 * run by then. Never on the query bus: queries are not acts.
 *
 * ddd-wordpress/self/tactician.yaml defines its own chain;
 * ddd-wordpress/self/services.yaml imports di/services.yaml, which
 * registers the middleware for both containers.
 */
class IntentsMiddlewareWiringTest extends TestCase {

  private const MIDDLEWARE = '@TangibleDDD\Application\Intents\IntentsMiddleware';

  /** @return array<string, array{services: array<string, mixed>}> */
  public static function chain_sources(): array {
    $root = dirname(__DIR__, 3);
    return [
      'di/tactician.yaml' => [Yaml::parseFile($root . '/ddd-wordpress/di/tactician.yaml')['services']],
      'self/tactician.yaml' => [Yaml::parseFile($root . '/ddd-wordpress/self/tactician.yaml')['services']],
    ];
  }

  #[DataProvider('chain_sources')]
  public function test_command_chain_has_the_middleware_between_transaction_and_event_publishing(array $services): void {
    $this->assertSame(
      [
        '@TangibleDDD\Application\Correlation\CorrelationMiddleware',
        '@TangibleDDD\Application\Persistence\TransactionMiddleware',
        self::MIDDLEWARE,
        '@TangibleDDD\Application\Events\DomainEventsPublishMiddleware',
        '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware',
        '@tactician.middleware.command_handler',
      ],
      $services['League\Tactician\CommandBus']['arguments']
    );
  }

  #[DataProvider('chain_sources')]
  public function test_query_chain_does_not_carry_the_middleware(array $services): void {
    $this->assertNotContains(self::MIDDLEWARE, $services['tactician.query_bus']['arguments']);
  }

  public function test_the_middleware_is_registered_for_both_containers(): void {
    $root = dirname(__DIR__, 3);
    $services = Yaml::parseFile($root . '/ddd-wordpress/di/services.yaml')['services'];
    $self = Yaml::parseFile($root . '/ddd-wordpress/self/services.yaml');

    $this->assertArrayHasKey(ltrim(self::MIDDLEWARE, '@'), $services);
    $this->assertContains(['resource' => '../di/services.yaml'], $self['imports']);
  }
}
