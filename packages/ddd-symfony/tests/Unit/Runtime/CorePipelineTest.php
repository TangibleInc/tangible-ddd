<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use League\Tactician\CommandBus;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\Services\FactPublishedInsideProcess;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditEverything;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Symfony\Runtime\FactClassRecordingEventBus;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The act bracket and the integration bus the bundle wires are the CORE
 * classes (wave-2 carry-over, CR sf-6 rows 1 and 2): CorrelationMiddleware
 * with the audit ports, and OutboxIntegrationEventBus in its port form
 * behind the sf class-recording decorator.
 */
final class CorePipelineTest extends TestCase {

  private FrozenClock $clock;
  private SymfonyConsumerConfig $consumer;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->consumer = new SymfonyConsumerConfig('sft', 'App');
    HostDefaults::provide(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
  }

  protected function tearDown(): void {
    Correlation::reset();
    HostDefaults::reset_for_tests();
  }

  private function bracket(IAuditSink $sink, ?IAuditPolicy $policy = null): CorrelationMiddleware {
    return new CorrelationMiddleware(
      $this->consumer, new EventsUnitOfWork(), new Redactor(), $sink,
      new FixedActorProvider(new Actor(ActorKind::User, 'u-1')), $policy ?? new AuditEverything(), new PhpEnvironmentProvider(),
    );
  }

  public function test_the_act_bracket_scopes_the_command_and_audits_it(): void {
    $sink = new InMemoryAuditSink();
    $seen = null;
    $bus = new CommandBus($this->bracket($sink), new class ($seen) implements \League\Tactician\Middleware {
      public function __construct(public mixed &$seen) {}
      public function execute($command, callable $next) {
        $this->seen = Correlation::peek();
        return 'receipt';
      }
    });

    $result = $bus->handle(new \ArrayObject(['secret_token' => 'x']));

    self::assertSame('receipt', $result);
    self::assertSame(Kind::Act, $seen?->cause?->kind);
    self::assertNull(Correlation::peek());
    self::assertCount(1, $sink->opened);
    self::assertSame('u-1', $sink->opened[0]->actor->id);
    self::assertSame('success', $sink->closed[0]->status);
  }

  public function test_the_nesting_guard_holds_with_audit_off(): void {
    $off = new class implements IAuditPolicy {
      public function audits(object $command): bool {
        return false;
      }
      public function captures_parameters(object $command): bool {
        return false;
      }
    };
    $bracket = $this->bracket(new InMemoryAuditSink(), $off);

    $this->expectException(CommandDispatchedInsideCommand::class);
    Correlation::within(TraceContext::root()->for_act('outer', 'Outer'), fn () => $bracket->execute(new \stdClass(), fn () => null));
  }

  public function test_an_audit_close_failure_never_replaces_the_outcome_and_is_signalled(): void {
    $signals = new RecordingSignalDispatcher();
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $signals);
    $sink = new class implements IAuditSink {
      public function open(AuditOpen $r): void {}
      public function close(AuditClose $r): void {
        throw new \RuntimeException('audit db gone');
      }
    };

    self::assertSame('ok', $this->bracket($sink)->execute(new \stdClass(), fn () => 'ok'));
    self::assertCount(1, $signals->emitted);
    self::assertInstanceOf(\TangibleDDD\Application\Infrastructure\AuditSinkFailed::class, $signals->emitted[0]['event']);
  }

  public function test_the_outbox_bus_stamps_the_record_with_an_absolute_due_time(): void {
    $store = new InMemoryOutboxStore($this->clock);
    $bus = Factory::integration_bus($store, $this->clock, $this->consumer, new \TangibleDDD\Application\Outbox\OutboxConfig());

    Correlation::within(TraceContext::root()->for_act('cmd-9', 'Cmd'), fn () => $bus->publish(new PingFact(3)));

    [$claim] = $store->claim(10, $this->clock->now(), 60);
    self::assertSame('ping_fact', $claim->record->event_type);
    self::assertSame('sft_integration_ping_fact', $claim->record->integration_action);
    self::assertSame('cmd-9', $claim->record->command_id);
    self::assertSame(['n' => 3], $claim->record->payload);
    self::assertEquals($this->clock->now(), $claim->record->due_at);
  }

  public function test_the_bus_is_the_core_bus_behind_the_class_recording_decorator(): void {
    $bus = Factory::integration_bus(new InMemoryOutboxStore($this->clock), $this->clock, $this->consumer, new \TangibleDDD\Application\Outbox\OutboxConfig());

    self::assertInstanceOf(FactClassRecordingEventBus::class, $bus);
    self::assertInstanceOf(OutboxIntegrationEventBus::class, $bus->inner());
  }

  public function test_the_outbox_bus_refuses_a_fact_published_from_a_process_step(): void {
    $bus = Factory::integration_bus(new InMemoryOutboxStore($this->clock), $this->clock, $this->consumer, new \TangibleDDD\Application\Outbox\OutboxConfig());

    $this->expectException(FactPublishedInsideProcess::class);
    Correlation::within(TraceContext::root()->for_trajectory('7', 'P'), fn () => $bus->publish(new PingFact()));
  }
}
