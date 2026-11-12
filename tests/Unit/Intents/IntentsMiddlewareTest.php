<?php

namespace TangibleDDD\Tests\Unit\Intents;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Intents\IntentWindows;
use TangibleDDD\Application\Intents\Intents;
use TangibleDDD\Application\Intents\IntentsMiddleware;
use TangibleDDD\Application\Intents\UndecidedIntents;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Persistence\TransactionMiddleware;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\Intents\FakeInnerCommand;
use TangibleDDD\Tests\Fakes\Intents\FakeOtherInnerCommand;
use TangibleDDD\Tests\Fakes\Intents\FakeOuterCommand;
use TangibleDDD\Tests\Fakes\Intents\FakeTransactionalOuterCommand;

/**
 * The guard captures inside a window; the act checks at its end. The bus is
 * the real order: CorrelationMiddleware -> TransactionMiddleware ->
 * IntentsMiddleware -> a terminal that runs the test's handler. A handler
 * sends by handing the inner command to the same bus.
 */
class IntentsMiddlewareTest extends TestCase {

  /** @var array<int, array> */
  private array $inserts = [];
  /** @var array<int, array> */
  private array $updates = [];
  /** @var list<string> */
  private array $queries = [];
  /** @var array<class-string, callable> */
  private array $handlers = [];

  private CommandBus $bus;

  protected function setUp(): void {
    Correlation::reset();
    Reactions::reset();
    IntentWindows::reset();
    $this->inserts = [];
    $this->updates = [];
    $this->queries = [];
    $this->handlers = [];
  }

  protected function tearDown(): void {
    Correlation::reset();
    IntentWindows::reset();
  }

  /** $prefix must be unique per test: command_audit_enabled() caches per prefix. */
  private function make_bus(string $prefix, bool $audit_enabled = true): CommandBus {
    $config = new class($prefix) implements IDDDConfig {
      public function __construct(private string $p) {}
      public function prefix(): string { return $this->p; }
      public function table(string $name): string { return 'wp_' . $this->p . '_' . $name; }
      public function hook(string $name): string { return $this->p . '_' . $name; }
      public function as_group(string $name): string { return $this->p . '-' . $name; }
      public function option(string $name): string { return $this->p . '_' . $name; }
      public function domain_action(string $e): string { return $this->p . '_domain_' . $e; }
      public function integration_action(string $e): string { return $this->p . '_integration_' . $e; }
      public function version(): string { return $this->p; }
    };

    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn($audit_enabled ? $config->table('command_audit') : null);
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('insert')->willReturnCallback(function($table, $row) {
      $this->inserts[] = $row;
      return true;
    });
    $wpdb->method('update')->willReturnCallback(function($table, $row) {
      $this->updates[] = $row;
      return true;
    });
    $wpdb->method('query')->willReturnCallback(function($sql) {
      $this->queries[] = $sql;
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;

    $terminal = new class(fn(object $command) => $this->run_handler($command)) implements Middleware {
      public function __construct(private \Closure $run) {}
      public function execute(object $command, callable $next) {
        return ($this->run)($command);
      }
    };

    $this->bus = new CommandBus(
      new CorrelationMiddleware($config, new EventsUnitOfWork(), new Redactor()),
      new TransactionMiddleware($wpdb),
      new IntentsMiddleware(),
      $terminal,
    );
    return $this->bus;
  }

  private function handle(string $class, callable $handler): void {
    $this->handlers[$class] = $handler;
  }

  private function run_handler(object $command): mixed {
    $handler = $this->handlers[get_class($command)] ?? null;
    if( $handler === null ) {
      throw new \LogicException('No test handler for ' . get_class($command));
    }
    return $handler($command);
  }

  /** @return list<array> the finalise row's intents, decoded */
  private function audited_intents(int $update = 0): array {
    return json_decode($this->updates[$update]['intents'], true);
  }

  public function test_send_outside_a_window_still_throws(): void {
    $this->make_bus('intm1');
    $line = 0;
    $this->handle(FakeOuterCommand::class, function() use (&$line) {
      $line = __LINE__ + 1;
      $this->bus->handle(new FakeInnerCommand());
    });

    try {
      $this->bus->handle(new FakeOuterCommand());
      $this->fail('a send outside a window must throw');
    } catch (CommandDispatchedInsideCommand $e) {
      $this->assertStringContainsString(FakeInnerCommand::class, $e->getMessage());
      $this->assertStringContainsString(FakeOuterCommand::class, $e->getMessage());
      $sites = array_map(
        fn(array $frame) => ($frame['file'] ?? '') . ':' . ($frame['line'] ?? ''),
        $e->getTrace()
      );
      $this->assertContains(__FILE__ . ':' . $line, $sites, 'the trace points at the handler\'s send');
    }
  }

  public function test_send_inside_a_window_is_captured_not_run(): void {
    $this->make_bus('intm2');
    $inner = new FakeInnerCommand();
    $inner_ran = false;
    $this->handle(FakeInnerCommand::class, function() use (&$inner_ran) {
      $inner_ran = true;
    });
    $this->handle(FakeOuterCommand::class, function() use ($inner) {
      $sent = 'not called';
      $captured = Intents::capture(function() use ($inner, &$sent) {
        $sent = $this->bus->handle($inner);
      });
      return [$sent, $captured->fulfil()];
    });

    [$sent, $fulfilled] = $this->bus->handle(new FakeOuterCommand());

    $this->assertFalse($inner_ran, 'a captured command is not run');
    $this->assertNull($sent, 'the sender gets null');
    $this->assertSame([$inner], $fulfilled);
    $this->assertCount(1, $this->inserts, 'no audit row for the captured command');
  }

  public function test_undecided_intent_fails_the_act_and_rolls_back(): void {
    $this->make_bus('intm3');
    $line = 0;
    $this->handle(FakeTransactionalOuterCommand::class, function() use (&$line) {
      Intents::capture(function() use (&$line) {
        $line = __LINE__ + 1;
        $this->bus->handle(new FakeInnerCommand());
      });
    });

    try {
      $this->bus->handle(new FakeTransactionalOuterCommand());
      $this->fail('an undecided intent fails the act');
    } catch (UndecidedIntents $e) {
      $this->assertStringContainsString(FakeInnerCommand::class, $e->getMessage());
      $this->assertStringContainsString(__FILE__ . ':' . $line, $e->getMessage());
    }

    $this->assertSame(['START TRANSACTION', 'ROLLBACK'], $this->queries);
    $this->assertSame('error', $this->updates[0]['status']);
  }

  public function test_reject_lets_the_act_commit_and_is_audited(): void {
    $this->make_bus('intm4');
    $line = 0;
    $this->handle(FakeTransactionalOuterCommand::class, function() use (&$line) {
      $captured = Intents::capture(function() use (&$line) {
        $line = __LINE__ + 1;
        $this->bus->handle(new FakeInnerCommand());
      });
      $captured->reject('mine');
      return 'ok';
    });

    $this->assertSame('ok', $this->bus->handle(new FakeTransactionalOuterCommand()));

    $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->queries);
    $this->assertCount(1, $this->updates);
    $this->assertSame('success', $this->updates[0]['status']);
    $this->assertSame([[
      'command'  => FakeInnerCommand::class,
      'origin'   => __FILE__ . ':' . $line,
      'decision' => 'rejected',
      'reason'   => 'mine',
    ]], $this->audited_intents());
  }

  public function test_caught_callback_failure_still_fails_the_act(): void {
    $this->make_bus('intm5');
    $this->handle(FakeOuterCommand::class, function() {
      try {
        Intents::capture(function() {
          $this->bus->handle(new FakeInnerCommand());
          throw new \RuntimeException('foreign code failed');
        });
      } catch (\RuntimeException) {
        // The handler swallows the foreign failure; the intent stays undecided.
      }
      return 'carried on';
    });

    $this->expectException(UndecidedIntents::class);
    $this->bus->handle(new FakeOuterCommand());
  }

  public function test_without_an_act_send_runs_normally(): void {
    $this->make_bus('intm6');
    $this->handle(FakeInnerCommand::class, fn() => 'ran');

    $this->assertSame('ran', $this->bus->handle(new FakeInnerCommand()));
    $this->assertCount(1, $this->inserts);
    $this->assertSame('success', $this->updates[0]['status']);
  }

  public function test_of_leaves_other_classes_undecided(): void {
    $this->make_bus('intm7');
    $this->handle(FakeOuterCommand::class, function() {
      $captured = Intents::capture(function() {
        $this->bus->handle(new FakeInnerCommand());
        $this->bus->handle(new FakeOtherInnerCommand());
      });
      $captured->of(FakeInnerCommand::class)->reject('mine');
    });

    try {
      $this->bus->handle(new FakeOuterCommand());
      $this->fail('the other class is still undecided');
    } catch (UndecidedIntents $e) {
      $this->assertStringContainsString(FakeOtherInnerCommand::class, $e->getMessage());
      $this->assertStringStartsWith('1 captured intent(s) left undecided: ', $e->getMessage());
    }
  }

  public function test_non_command_inside_a_window_throws(): void {
    $this->make_bus('intm8');
    $this->handle(FakeOuterCommand::class, function() {
      Intents::capture(function() {
        $this->bus->handle(new \stdClass());
      });
    });

    $this->expectException(CommandDispatchedInsideCommand::class);
    $this->expectExceptionMessage('stdClass dispatched inside');
    $this->bus->handle(new FakeOuterCommand());
  }

  public function test_handler_exception_is_not_masked_by_undecided_intents(): void {
    $this->make_bus('intm9');
    $this->handle(FakeOuterCommand::class, function() {
      Intents::capture(function() {
        $this->bus->handle(new FakeInnerCommand());
      });
      throw new \DomainException('boom');
    });

    try {
      $this->bus->handle(new FakeOuterCommand());
      $this->fail('the handler\'s exception must propagate');
    } catch (\DomainException $e) {
      $this->assertSame('boom', $e->getMessage());
    }

    $this->assertSame('error', $this->updates[0]['status']);
    $this->assertSame('undecided', $this->audited_intents()[0]['decision']);
  }

  public function test_act_state_is_forgotten_even_with_audit_disabled(): void {
    $this->make_bus('intm10', audit_enabled: false);
    $act_ids = [];
    $this->handle(FakeOuterCommand::class, function() use (&$act_ids) {
      $act_ids[] = Correlation::current()->cause->id;
      $captured = Intents::capture(function() {
        $this->bus->handle(new FakeInnerCommand());
      });
      $captured->reject('mine');
      return $captured;
    });

    $this->bus->handle(new FakeOuterCommand());
    $second = $this->bus->handle(new FakeOuterCommand());

    $this->assertCount(1, $second);
    $this->assertSame([], IntentWindows::record($act_ids[0]));
    $this->assertSame([], IntentWindows::record($act_ids[1]));
    $this->assertSame([], $this->updates, 'no audit rows');
  }
}
