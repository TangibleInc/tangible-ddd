<?php

namespace TangibleDDD\Tests\Unit\CQRS;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler;
use TangibleDDD\Application\Exceptions\SelfHandlingCommandWrapsHandler;
use TangibleDDD\Application\Exceptions\UnresolvableHandleDependency;

/**
 * The self-handling command middleware (spec §14 item 1, 0.6.0 target).
 *
 * A SelfHandlingCommand carries its own protected handle(...$deps): void; the
 * middleware is its TERMINAL — it reflects handle(), method-injects the typed
 * dependencies from the container (Symfony has no container->call(), so the
 * middleware does the reflect+resolve itself), invokes it, and propagates
 * whatever it returns (void → null per the receipt rule). A plain command is
 * passed straight to $next untouched — the two-class ceremony still works.
 *
 * Constructed directly with a fake PSR container, mirroring the unit style of
 * ActFootprintTest / OutboxBusTouchesTest (no live container, no bus boot).
 */
class SelfExecutingCommandMiddlewareTest extends TestCase {

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
      throw new \LogicException('$next must not be called for a self-handling command');
    };
  }

  public function test_handle_is_invoked_and_its_return_propagates_out(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());

    $command = new class extends SelfHandlingCommand {
      protected function handle(): string {
        return 'verdict:ok';
      }
    };

    $result = $middleware->execute($command, $this->fail_next());

    $this->assertSame('verdict:ok', $result);
  }

  public function test_next_is_not_called_for_a_self_handling_command(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $next_called = false;
    $next = static function () use (&$next_called): void {
      $next_called = true;
    };

    $command = new class extends SelfHandlingCommand {
      protected function handle(): string {
        return 'x';
      }
    };

    $middleware->execute($command, $next);

    $this->assertFalse($next_called, 'the middleware is the terminal for a self-handling command');
  }

  public function test_typed_dependencies_are_method_injected_from_the_container(): void {
    $depA = new HandleDepA();
    $depB = new HandleDepB();
    $middleware = new SelfExecutingCommandMiddleware($this->make_container([
      HandleDepA::class => $depA,
      HandleDepB::class => $depB,
    ]));

    $command = new class extends SelfHandlingCommand {
      public ?HandleDepA $got_a = null;
      public ?HandleDepB $got_b = null;
      protected function handle(HandleDepA $a, HandleDepB $b): void {
        $this->got_a = $a;
        $this->got_b = $b;
      }
    };

    $middleware->execute($command, $this->fail_next());

    $this->assertSame($depA, $command->got_a, 'exact resolved instance arrives');
    $this->assertSame($depB, $command->got_b, 'exact resolved instance arrives');
  }

  public function test_protected_handle_is_reachable_via_reflection(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());

    $command = new class extends SelfHandlingCommand {
      public bool $ran = false;
      protected function handle(): void {
        $this->ran = true;
      }
    };

    // The method really is protected — no public escape hatch.
    $this->assertTrue((new \ReflectionMethod($command, 'handle'))->isProtected());

    $middleware->execute($command, $this->fail_next());

    $this->assertTrue($command->ran, 'the middleware invoked the protected method');
  }

  public function test_void_handle_yields_null_out_of_the_middleware(): void {
    // The receipt rule's permanent default: handle() is void, the pass returns null.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());

    $command = new class extends SelfHandlingCommand {
      protected function handle(): void {}
    };

    $this->assertNull($middleware->execute($command, $this->fail_next()));
  }

  public function test_plain_command_is_passed_straight_to_next_untouched(): void {
    // NOT a SelfHandlingCommand — even though it HAS a handle() method, the
    // instanceof gate (not method_exists) decides: it routes normally.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $command = new PlainCommandWithHandle();

    $result = $middleware->execute($command, static function ($passed) use ($command) {
      // The same command instance reaches $next, unmodified.
      return $passed === $command ? 'routed-normally' : 'wrong-command';
    });

    $this->assertSame('routed-normally', $result);
    $this->assertFalse($command->handle_was_called, 'handle() logic is never consulted for a plain command');
  }

  public function test_self_handling_command_without_a_handle_method_throws(): void {
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $command = new class extends SelfHandlingCommand {};

    $this->expectException(SelfHandlingCommandHasNoHandler::class);

    $middleware->execute($command, $this->fail_next());
  }

  public function test_unmethod_injectable_handle_param_throws(): void {
    // Untyped param with no default: nothing to resolve from the container.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $command = new class extends SelfHandlingCommand {
      protected function handle($mystery): void {}
    };

    $this->expectException(UnresolvableHandleDependency::class);

    $middleware->execute($command, $this->fail_next());
  }

  public function test_handle_param_that_is_a_command_handler_throws_the_conformance_guard(): void {
    // The chimera shape: a self-handling command method-injecting an
    // ICommandHandler and delegating to it wraps one blessed CQRS shape in
    // the other. The guard fires BEFORE the container is consulted — even a
    // container that could resolve the handler must not.
    $handler = new WrappedHandler();
    $middleware = new SelfExecutingCommandMiddleware($this->make_container([
      WrappedHandler::class => $handler,
    ]));

    $command = new class extends SelfHandlingCommand {
      protected function handle(WrappedHandler $handler): void {
        $handler->handle($this);
      }
    };

    $this->expectException(SelfHandlingCommandWrapsHandler::class);
    $this->expectExceptionMessage('pick one shape');

    try {
      $middleware->execute($command, $this->fail_next());
    } finally {
      $this->assertFalse($handler->handled, 'the wrapped handler never runs');
    }
  }

  public function test_handle_param_typed_as_a_handler_subinterface_also_throws(): void {
    // is_a semantics: anything that IMPLEMENTS ICommandHandler (directly or
    // transitively — e.g. a WorkflowHandler subclass) is caught, not only
    // params typed as the interface itself.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());

    $command = new class extends SelfHandlingCommand {
      protected function handle(ICommandHandler $handler): void {}
    };

    $this->expectException(SelfHandlingCommandWrapsHandler::class);

    $middleware->execute($command, $this->fail_next());
  }

  public function test_handle_param_with_a_default_uses_the_default(): void {
    // A builtin-typed param with a default is not injected — the default stands.
    $middleware = new SelfExecutingCommandMiddleware($this->make_container());
    $command = new class extends SelfHandlingCommand {
      public ?int $seen = null;
      protected function handle(int $limit = 42): void {
        $this->seen = $limit;
      }
    };

    $middleware->execute($command, $this->fail_next());

    $this->assertSame(42, $command->seen);
  }
}

class HandleDepA {}
class HandleDepB {}

class WrappedHandler implements ICommandHandler {
  public bool $handled = false;
  public function handle(ICommand $command): void {
    $this->handled = true;
  }
}

class PlainCommandWithHandle {
  public bool $handle_was_called = false;
  public function handle(): string {
    $this->handle_was_called = true;
    return 'should-never-run';
  }
}
