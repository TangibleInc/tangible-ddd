<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

use function TangibleDDD\WordPress\register_self_consumer;

/**
 * Register 1.4: the core Command base no longer calls SelfConsumer\di();
 * ddd-wp registers the self-consumer in ConsumerRegistry at init instead.
 */
final class SelfConsumerRegistrationTest extends TestCase {

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_framework_commands_route_to_the_self_consumer_container(): void {
    $config = new FakeDDDConfig();
    $container = new class($config) implements ContainerInterface {
      public function __construct(private IDDDConfig $config) {}
      public function get(string $id): mixed { return $this->config; }
      public function has(string $id): bool { return $id === IDDDConfig::class; }
    };

    register_self_consumer(static fn () => $container);

    $owner = ConsumerRegistry::owner_of(RetryDeliveryCommand::class);
    self::assertSame($container, $owner->container());
    self::assertSame('TangibleDDD\\Application\\Commands', $owner->namespace_root());
  }

  public function test_a_missing_self_container_registers_nothing(): void {
    register_self_consumer(static fn () => null);
    self::assertSame([], ConsumerRegistry::all());
  }
}
