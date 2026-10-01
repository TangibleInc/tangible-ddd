<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;

/**
 * CONF-1: the core act bracket. Nesting guard first and unconditional,
 * Correlation::within(for_act), audit only through the ports, no WordPress
 * call on any path (this suite has no WordPress loaded).
 */
final class CorrelationMiddlewareCoreTest extends TestCase {

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  private function bracket(?IAuditSink $sink = null, ?IAuditPolicy $policy = null): CorrelationMiddleware {
    return new CorrelationMiddleware(
      new AcmeConfig('acme', '2.1.0'),
      new EventsUnitOfWork(),
      new Redactor(),
      $sink,
      new FixedActorProvider(new Actor(ActorKind::User, '42')),
      $policy,
    );
  }

  private function command(): object {
    return new class {
      public string $email = 'a@b.c';
      public string $password = 'hunter2';
    };
  }

  public function test_runs_the_command_inside_an_act_scope_and_returns_its_value(): void {
    $seen = null;
    $result = $this->bracket()->execute($this->command(), function () use (&$seen) {
      $seen = Correlation::peek()?->cause;
      return 'receipt';
    });

    self::assertSame('receipt', $result);
    self::assertSame(Kind::Act, $seen?->kind);
    self::assertNull(Correlation::peek(), 'scope closed');
  }

  public function test_the_nesting_guard_holds_with_audit_off_and_before_the_sink(): void {
    $sink = new InMemoryAuditSink();
    $bracket = $this->bracket($sink);

    try {
      Correlation::within((new TraceContext('c'))->for_act('outer', 'Outer'), fn () => $bracket->execute($this->command(), fn () => 'x'));
      self::fail('expected the nesting guard');
    } catch (CommandDispatchedInsideCommand) {
    }
    self::assertSame([], $sink->opened, 'the guard runs before the audit row');

    $this->expectException(CommandDispatchedInsideCommand::class);
    $off = $this->bracket();   // no sink anywhere: audit off
    Correlation::within((new TraceContext('c'))->for_act('outer', 'Outer'), fn () => $off->execute($this->command(), fn () => 'x'));
  }

  public function test_audit_rows_go_through_the_sink_with_actor_environment_and_redaction(): void {
    $sink = new InMemoryAuditSink();
    Correlation::within((new TraceContext('story-1'))->for_fact('evt-9', 'X'), fn () =>
      $this->bracket($sink)->execute($this->command(), fn () => 'ok'));

    self::assertCount(1, $sink->opened);
    $open = $sink->opened[0];
    self::assertSame('story-1', $open->correlation_id);
    self::assertSame('evt-9', $open->causation_id);
    self::assertSame('integration_event', $open->causation_type);
    self::assertSame(ActorKind::User, $open->actor->kind);
    self::assertSame('42', $open->actor->id);
    self::assertSame('a@b.c', $open->parameters['email']);
    self::assertNotSame('hunter2', $open->parameters['password'], 'parameters are redacted');
    self::assertSame(PHP_VERSION, $open->environment['php']);
    self::assertSame('2.1.0', $open->environment['plugin']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $open->command_id);

    self::assertCount(1, $sink->closed);
    self::assertSame($open->command_id, $sink->closed[0]->command_id);
    self::assertSame('success', $sink->closed[0]->status);
  }

  public function test_a_failing_command_closes_its_row_with_the_error(): void {
    $sink = new InMemoryAuditSink();
    try {
      $this->bracket($sink)->execute($this->command(), static fn () => throw new \DomainException('nope', 7));
    } catch (\DomainException) {
    }

    self::assertSame('error', $sink->closed[0]->status);
    self::assertSame(['type' => \DomainException::class, 'message' => 'nope', 'code' => 7], $sink->closed[0]->error);
  }

  public function test_a_policy_can_skip_the_write_but_not_the_guard(): void {
    $sink = new InMemoryAuditSink();
    $skipAll = new class implements IAuditPolicy {
      public function audits(object $command): bool { return false; }
      public function captures_parameters(object $command): bool { return false; }
    };

    $this->bracket($sink, $skipAll)->execute($this->command(), fn () => null);

    self::assertSame([], $sink->opened);
    self::assertSame([], $sink->closed);
  }

  public function test_parameters_are_not_captured_when_the_policy_says_so(): void {
    $sink = new InMemoryAuditSink();
    $noParams = new class implements IAuditPolicy {
      public function audits(object $command): bool { return true; }
      public function captures_parameters(object $command): bool { return false; }
    };

    $this->bracket($sink, $noParams)->execute($this->command(), fn () => null);

    self::assertSame([], $sink->opened[0]->parameters);
  }

  public function test_a_sink_failure_after_the_command_is_logged_and_signalled_and_the_result_stands(): void {
    // audit.sink-fails
    $logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $logger);
    $signals = new class implements IInfrastructureSignalDispatcher {
      public array $seen = [];
      public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void { $this->seen[] = $c->prefix() . '_' . $e::action(); }
    };
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $signals);

    $sink = new InMemoryAuditSink(failOnClose: new \RuntimeException('audit table gone'));
    $result = $this->bracket($sink)->execute($this->command(), fn () => 'committed');

    self::assertSame('committed', $result);
    self::assertSame(['acme_audit_sink_failed'], $signals->seen);
    self::assertStringContainsString('audit table gone', implode("\n", $logger->messages()));
  }

  public function test_a_sink_failure_at_open_never_blocks_the_command(): void {
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    $ran = false;
    $sink = new InMemoryAuditSink(failOnOpen: new \RuntimeException('down'));

    $this->bracket($sink)->execute($this->command(), function () use (&$ran) { $ran = true; });

    self::assertTrue($ran);
    self::assertSame([], $sink->closed, 'no close for a row that never opened');
  }

  public function test_the_host_supplies_the_per_consumer_sink(): void {
    $sink = new InMemoryAuditSink();
    HostDefaults::provide(IHostPortFactory::class, new class($sink) implements IHostPortFactory {
      public function __construct(private IAuditSink $sink) {}
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        return $port === IAuditSink::class && $consumer->prefix() === 'acme' ? $this->sink : null;
      }
    });

    $this->bracket()->execute($this->command(), fn () => null);

    self::assertCount(1, $sink->opened);
  }

  public function test_a_deterministic_command_id_hint_becomes_the_command_id(): void {
    $sink = new InMemoryAuditSink();
    $bracket = $this->bracket($sink);
    $command = $this->command();

    $seen = DeterministicCommandId::within(str_repeat('ab', 16), static fn () => $bracket->execute(
      $command,
      static fn () => Correlation::peek()?->cause?->id
    ));

    self::assertSame(str_repeat('ab', 16), $seen);
    self::assertSame(str_repeat('ab', 16), $sink->opened[0]->command_id);
  }
}
