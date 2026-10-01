<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\Actor\ConsoleOperatorActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SecurityUserActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SymfonyActorProvider;

final class SymfonyActorProviderTest extends TestCase {

  private TokenStorage $tokens;
  private ActorContext $context;
  private ConsoleOperatorActorProvider $console;
  private SymfonyActorProvider $provider;

  protected function setUp(): void {
    $this->tokens = new TokenStorage();
    $this->context = new ActorContext();
    $this->console = new ConsoleOperatorActorProvider();
    $this->provider = new SymfonyActorProvider($this->context, new SecurityUserActorProvider($this->tokens), $this->console);
    putenv('DDD_OPERATOR');
  }

  protected function tearDown(): void {
    putenv('DDD_OPERATOR');
  }

  public function test_the_session_user_is_the_actor(): void {
    $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana@example.test', null), 'main'));

    $actor = $this->provider->current();

    self::assertSame(ActorKind::User, $actor->kind);
    self::assertSame('ana@example.test', $actor->id);
  }

  public function test_a_console_command_is_its_operator(): void {
    putenv('DDD_OPERATOR=ops-oncall');
    $this->console->enter('app:repair');

    $actor = $this->provider->current();

    self::assertSame(ActorKind::Cli, $actor->kind);
    self::assertSame('ops-oncall', $actor->id);
    self::assertSame('app:repair', $actor->label);
  }

  public function test_an_unattended_worker_is_system(): void {
    $this->console->enter('messenger:consume');
    self::assertSame(ActorKind::System, $this->provider->current()->kind);
  }

  public function test_an_explicit_machine_actor_wins_and_is_reset(): void {
    $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null), 'main'));
    $machine = new Actor(ActorKind::Machine, 'runner-7.example', 'runner');

    $seen = $this->context->runAs($machine, fn () => $this->provider->current());
    self::assertSame($machine, $seen);
    self::assertSame(ActorKind::User, $this->provider->current()->kind, 'runAs restores');

    $this->context->set($machine);
    $this->context->reset();
    self::assertSame(ActorKind::User, $this->provider->current()->kind);
  }

  public function test_without_any_source_it_falls_back_to_the_sapi(): void {
    self::assertSame(ActorKind::Cli, $this->provider->current()->kind); // phpunit runs under the CLI SAPI
    self::assertNull((new SecurityUserActorProvider(null))->resolve());
  }
}
