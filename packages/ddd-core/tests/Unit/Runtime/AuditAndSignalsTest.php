<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Infrastructure\InfrastructureEvent;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditEverything;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Audit\SapiActorProvider;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\LoggingSignalDispatcher;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\RecordingSignalDispatcher;
use TangibleDDD\Testing\StaticConsumerIdentity;

final class AuditAndSignalsTest extends TestCase {

  private function open(): AuditOpen {
    return new AuditOpen(
      commandId: 'c1',
      correlationId: 'corr',
      commandName: 'Acme\\PlaceOrder',
      actor: new Actor(ActorKind::User, '7', 'ann'),
      causationId: null,
      causationType: null,
      parameters: ['sku' => 'x'],
      environment: ['php' => PHP_VERSION],
      startedAt: new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')),
    );
  }

  public function test_actor_kinds_have_persisted_values(): void {
    self::assertSame(['user', 'cli', 'system', 'machine'], array_map(static fn (ActorKind $k) => $k->value, ActorKind::cases()));
    $a = new Actor(ActorKind::Machine, 'svc-billing');
    self::assertNull($a->label);
  }

  public function test_sapi_actor_provider_is_cli_under_phpunit(): void {
    $p = new SapiActorProvider();
    self::assertInstanceOf(IActorProvider::class, $p);
    self::assertSame(ActorKind::Cli, $p->current()->kind);
  }

  public function test_fixed_actor_provider_returns_what_it_was_given(): void {
    $actor = new Actor(ActorKind::User, '1');
    self::assertSame($actor, (new FixedActorProvider($actor))->current());
    self::assertSame(ActorKind::System, (new FixedActorProvider())->current()->kind);
  }

  public function test_default_audit_policy_audits_everything_with_parameters(): void {
    $p = new AuditEverything();
    self::assertInstanceOf(IAuditPolicy::class, $p);
    self::assertTrue($p->audits(new \stdClass()));
    self::assertTrue($p->captureParameters(new \stdClass()));
  }

  public function test_null_sink_accepts_everything(): void {
    $s = new NullAuditSink();
    self::assertInstanceOf(IAuditSink::class, $s);
    $s->open($this->open());
    $s->close(new AuditClose('c1', 'success', 3, 1024, [], null));
    $this->addToAssertionCount(1);
  }

  public function test_in_memory_sink_records_and_can_fail_after_commit(): void {
    $s = new InMemoryAuditSink();
    $s->open($this->open());
    $s->close($close = new AuditClose('c1', 'error', 3, 1024, [], ['type' => 'X', 'message' => 'm', 'code' => 0]));

    self::assertSame('c1', $s->opened[0]->commandId);
    self::assertSame($close, $s->closed[0]);

    $failing = new InMemoryAuditSink(failOnClose: new \RuntimeException('audit table gone'));
    $failing->open($this->open());
    $this->expectExceptionMessage('audit table gone');
    $failing->close($close);
  }

  public function test_audit_close_rejects_an_unknown_status(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AuditClose('c1', 'meh', 0, 0, [], null);
  }

  public function test_php_environment_provider_describes_php(): void {
    $e = new PhpEnvironmentProvider(['plugin' => '1.0']);
    self::assertInstanceOf(IEnvironmentProvider::class, $e);
    self::assertSame(['php' => PHP_VERSION, 'plugin' => '1.0'], $e->describe());
  }

  private function signal(): InfrastructureEvent {
    return new class('subject', 'corr-1', 'evt-1', 'integration_event') extends InfrastructureEvent {
      public static function action(): string { return 'outbox_dlq'; }
    };
  }

  public function test_signals_are_never_silent_by_default(): void {
    $logged = [];
    $d = new LoggingSignalDispatcher(static function (string $m) use (&$logged) { $logged[] = $m; });
    self::assertInstanceOf(IInfrastructureSignalDispatcher::class, $d);

    $d->emit($this->signal(), new StaticConsumerIdentity('acme'));

    self::assertCount(1, $logged);
    self::assertStringContainsString('acme', $logged[0]);
    self::assertStringContainsString('outbox_dlq', $logged[0]);
    self::assertStringContainsString('corr-1', $logged[0]);
  }

  public function test_recording_signal_dispatcher(): void {
    $d = new RecordingSignalDispatcher();
    $signal = $this->signal();
    $d->emit($signal, new StaticConsumerIdentity('acme'));

    self::assertSame($signal, $d->emitted[0]['event']);
    self::assertSame('acme', $d->emitted[0]['consumer']->prefix());
  }
}
