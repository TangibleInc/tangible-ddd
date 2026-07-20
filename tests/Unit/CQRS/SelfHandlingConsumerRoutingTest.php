<?php

namespace TangibleDDD\Tests\Unit\CQRS;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Acme\Application\AcmeDoThingCommand;
use TangibleDDD\Tests\Fakes\Acme\Application\AcmeFindThingQuery;
use TangibleDDD\Tests\Fakes\Acme\Application\AcmeService;

/**
 * THE CONSUMER-ROUTING FIX: SelfHandlingCommand must NOT inherit the
 * self-consumer container pin from the framework's own Command base.
 *
 * A consumer command extending SelfHandlingCommand (Acme's, datastream's, …)
 * must resolve container()/send() through the registry default
 * (CommandBusAware: ConsumerRegistry::owner_of(static::class)->container(),
 * the 0.2.5c scheme) — i.e. to its OWN consumer's container and bus, where
 * its handle() dependencies actually live. Pinning it to the framework's
 * SelfConsumer di() would dispatch through the framework's bus and resolve
 * deps from a container where consumer services do not exist. Fatal.
 *
 * Fixture style mirrors RegistryResolvedBusTest: two consumers registered
 * (the framework self on root `TangibleDDD`, Acme on its Fakes subtree —
 * longest match wins), ConsumerRegistry::reset() in setUp/tearDown.
 */
class SelfHandlingConsumerRoutingTest extends TestCase {

  private ContainerInterface $acme_container;
  private ContainerInterface $framework_container;
  private AcmeService $acme_service;

  protected function setUp(): void {
    ConsumerRegistry::reset();

    $this->acme_service = new AcmeService();

    $this->acme_container = $this->make_container([
      AcmeService::class => $this->acme_service,
      CommandBus::class => $this->spy_bus('handled-by-acme'),
      'tactician.query_bus' => $this->spy_bus('read-from-acme'),
    ]);

    $this->framework_container = $this->make_container([
      CommandBus::class => $this->spy_bus('handled-by-framework-self'),
      'tactician.query_bus' => $this->spy_bus('read-from-framework-self'),
    ]);

    ConsumerRegistry::add(
      new DDDConfig(prefix: 'tangible_ddd', namespace_root: 'TangibleDDD', version: 't'),
      fn () => $this->framework_container,
    );
    ConsumerRegistry::add(
      new DDDConfig(prefix: 'acme', namespace_root: 'TangibleDDD\\Tests\\Fakes\\Acme', version: 't'),
      fn () => $this->acme_container,
    );
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  /** @param array<string, object> $services */
  private function make_container(array $services): ContainerInterface {
    return new class($services) implements ContainerInterface {
      /** @param array<string, object> $services */
      public function __construct(private array $services) {}
      public function get(string $id): mixed {
        if (!array_key_exists($id, $this->services)) {
          throw new class ("no service: $id") extends \RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->services[$id];
      }
      public function has(string $id): bool {
        return array_key_exists($id, $this->services);
      }
    };
  }

  private function spy_bus(string $verdict): CommandBus {
    $spy = new class($verdict) implements Middleware {
      public function __construct(private string $verdict) {}
      public function execute($command, callable $next) {
        return $this->verdict;
      }
    };

    return new CommandBus($spy);
  }

  public function test_container_resolves_to_the_owning_consumers_container(): void {
    $method = new \ReflectionMethod(AcmeDoThingCommand::class, 'container');
    $method->setAccessible(true);

    $this->assertSame(
      $this->acme_container,
      $method->invoke(null),
      'a consumer SelfHandlingCommand resolves owner_of(static::class) → ACME, '
      . 'not the framework self-consumer pin'
    );
  }

  public function test_send_rides_the_owning_consumers_bus_not_the_frameworks(): void {
    $result = (new AcmeDoThingCommand())->send();

    $this->assertSame('handled-by-acme', $result, 'dispatch went through Acme\'s bus');
  }

  public function test_middleware_with_the_owning_container_injects_the_consumers_service(): void {
    // As Acme's compiled chain would construct it: with ACME's container.
    $middleware = new SelfExecutingCommandMiddleware($this->acme_container);
    $command = new AcmeDoThingCommand();

    $middleware->execute($command, static function (): void {
      throw new \LogicException('$next must not be called for a self-handling command');
    });

    $this->assertSame(
      $this->acme_service,
      $command->got,
      'handle() received the exact AcmeService instance from Acme\'s container'
    );
  }

  // ── the QUERY side: same routing story, on the query bus ─────────────

  public function test_query_container_resolves_to_the_owning_consumers_container(): void {
    $method = new \ReflectionMethod(AcmeFindThingQuery::class, 'container');
    $method->setAccessible(true);

    $this->assertSame(
      $this->acme_container,
      $method->invoke(null),
      'a consumer SelfHandlingQuery resolves owner_of(static::class) → ACME'
    );
  }

  public function test_query_send_rides_the_owning_consumers_query_bus(): void {
    $result = (new AcmeFindThingQuery())->send();

    $this->assertSame('read-from-acme', $result, 'dispatch went through Acme\'s query bus');
  }

  public function test_query_middleware_injects_the_consumers_service_and_returns_the_read_result(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->acme_container);
    $query = new AcmeFindThingQuery();

    $result = $middleware->execute($query, static function (): void {
      throw new \LogicException('$next must not be called for a self-handling query');
    });

    $this->assertSame(
      ['found_with' => $this->acme_service],
      $result,
      'handle() got Acme\'s service and its RETURN VALUE propagated (queries return data)'
    );
  }
}
