<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use League\Tactician\CommandBus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\NoConsumerOwnsClass;
use TangibleDDD\Testing\StaticConsumerIdentity;

/** Register 1.4: the framework Command base routes through ConsumerRegistry, not a WordPress container. */
final class FrameworkCommandRoutingTest extends TestCase {

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_a_framework_command_rides_the_registered_self_consumer_bus(): void {
    $handled = [];
    $bus = new CommandBus(new class($handled) implements \League\Tactician\Middleware {
      public function __construct(private array &$handled) {}
      public function execute($command, callable $next) {
        $this->handled[] = $command;
        return 'handled';
      }
    });
    $container = new class($bus) implements ContainerInterface {
      public function __construct(private CommandBus $bus) {}
      public function get(string $id): mixed { return $this->bus; }
      public function has(string $id): bool { return $id === CommandBus::class; }
    };
    ConsumerRegistry::add(new StaticConsumerIdentity('tangible_ddd'), static fn () => $container, 'Tangible DDD', 'TangibleDDD\\Application\\Commands');

    $command = new PurgeOutboxCommand('acme', 30);
    self::assertSame('handled', $command->send());
    self::assertSame([$command], $handled);
  }

  public function test_without_a_registered_owner_the_send_fails_loudly(): void {
    $this->expectException(NoConsumerOwnsClass::class);
    (new PurgeOutboxCommand('acme', 30))->send();
  }
}
