<?php

namespace TangibleDDD\Tests\Unit\Consumers;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\NoConsumerOwnsClass;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Acme\Application\ShipWidget;

/**
 * CommandBusAware::container() gets a registry-resolved default (0.2.5c):
 * a consumer command needs no stamped base and no override — ->send()
 * finds its OWN consumer's bus through owner_of(static::class). Stamped
 * bases that override container() keep winning (the framework's
 * self-consumer Command base among them — it is stamp #1 and stays).
 */
class RegistryResolvedBusTest extends TestCase {

  private \ArrayObject $seen;

  protected function setUp(): void {
    ConsumerRegistry::reset();
    $this->seen = new \ArrayObject();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  private function spy_container(): ContainerInterface {
    $spy = new class($this->seen) implements Middleware {
      public function __construct(private \ArrayObject $seen) {}
      public function execute($command, callable $next) {
        $this->seen[] = $command;
        return 'handled-by-acme';
      }
    };
    $bus = new CommandBus($spy);

    return new class($bus) implements ContainerInterface {
      public function __construct(private CommandBus $bus) {}
      public function get(string $id): mixed { return $this->bus; }
      public function has(string $id): bool { return true; }
    };
  }

  public function test_send_routes_through_the_owning_consumers_bus(): void {
    ConsumerRegistry::add(
      new DDDConfig(prefix: 'acme', namespace_root: 'TangibleDDD\\Tests\\Fakes\\Acme', version: 't'),
      fn () => $this->spy_container(),
    );

    $result = (new ShipWidget(7))->send();

    $this->assertSame('handled-by-acme', $result);
    $this->assertCount(1, $this->seen);
    $this->assertInstanceOf(ShipWidget::class, $this->seen[0]);
  }

  public function test_unowned_command_fails_loudly_on_send(): void {
    $this->expectException(NoConsumerOwnsClass::class);

    (new ShipWidget(8))->send();
  }
}
