<?php

namespace TangibleDDD\Tests\Unit\CQRS;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler;
use TangibleDDD\Application\Exceptions\UnresolvableHandleDependency;
use TangibleDDD\Application\Queries\SelfHandlingQuery;

/**
 * The QUERY side of the self-handling middleware (0.6.0). ONE middleware
 * (SelfExecutingCommandMiddleware) recognizes both bases via an explicit
 * union check; a SelfHandlingQuery's handle() is method-injected the same
 * way — with the crucial asymmetry that its RETURN VALUE is the point:
 * queries return data by definition (no receipt rule for reads), and the
 * middleware propagates the read result out of the pass.
 *
 * Same unit style as SelfExecutingCommandMiddlewareTest: constructed
 * directly with a fake PSR container, no live container, no bus boot.
 */
class SelfExecutingQueryMiddlewareTest extends TestCase {

  private function make_container(array $services = []): ContainerInterface {
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

  private function fail_next(): callable {
    return static function (): void {
      throw new \LogicException('$next must not be called for a self-handling query');
    };
  }

  public function test_handle_runs_with_injected_deps_and_its_return_value_propagates(): void {
    $dep = new QueryHandleDep();
    $middleware = new SelfExecutingCommandMiddleware($this->make_container([
      QueryHandleDep::class => $dep,
    ]));

    $query = new class extends SelfHandlingQuery {
      public ?QueryHandleDep $got = null;
      protected function handle(QueryHandleDep $read_model): array {
        $this->got = $read_model;
        return ['id' => 42, 'name' => 'thing'];
      }
    };

    $result = $middleware->execute($query, $this->fail_next());

    $this->assertSame($dep, $query->got, 'exact resolved instance arrives');
    $this->assertSame(['id' => 42, 'name' => 'thing'], $result, 'the read result IS the return — no receipt rule for queries');
  }

  public function test_next_is_not_called_for_a_self_handling_query(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $next_called = false;
    $next = static function () use (&$next_called): void {
      $next_called = true;
    };

    $query = new class extends SelfHandlingQuery {
      protected function handle(): string {
        return 'x';
      }
    };

    $middleware->execute($query, $next);

    $this->assertFalse($next_called, 'the middleware is the terminal for a self-handling query');
  }

  public function test_plain_query_is_passed_straight_to_next_untouched(): void {
    // NOT a SelfHandlingQuery — even though it HAS a handle() method, the
    // instanceof gate decides: it routes to its convention-named handler.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $query = new PlainQueryWithHandle();

    $result = $middleware->execute($query, static function ($passed) use ($query) {
      return $passed === $query ? 'routed-normally' : 'wrong-query';
    });

    $this->assertSame('routed-normally', $result);
    $this->assertFalse($query->handle_was_called, 'handle() logic is never consulted for a plain query');
  }

  public function test_self_handling_query_without_a_handle_method_throws(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $query = new class extends SelfHandlingQuery {};

    $this->expectException(SelfHandlingCommandHasNoHandler::class);

    $middleware->execute($query, $this->fail_next());
  }

  public function test_unmethod_injectable_query_handle_param_throws(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $query = new class extends SelfHandlingQuery {
      protected function handle($mystery): array {
        return [];
      }
    };

    $this->expectException(UnresolvableHandleDependency::class);

    $middleware->execute($query, $this->fail_next());
  }
}

class QueryHandleDep {}

class PlainQueryWithHandle {
  public bool $handle_was_called = false;
  public function handle(): string {
    $this->handle_was_called = true;
    return 'should-never-run';
  }
}
