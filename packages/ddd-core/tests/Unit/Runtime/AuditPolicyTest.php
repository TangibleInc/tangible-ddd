<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\Audit;
use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;

#[Audit(false)]
final class Heartbeat {
  public string $job = 'j-1';
}

#[Audit(parameters: false)]
final class AcquireJob {
  public string $runner = 'r-1';
}

/** #[Audit] on a parent class applies to its subclasses. */
#[Audit(false)]
abstract class QuietCommand {}

final class Progress extends QuietCommand {
  public int $percent = 40;
}

interface IsChatty {}

final class ReportProgress implements IsChatty {
  public int $percent = 50;
}

final class CreateTeam {
  public string $name = 'Acme';
}

/**
 * D12: per-command audit policy. #[Audit(false)] and class lists switch the
 * audit WRITE off; the act-bracket guards hold regardless.
 */
final class AuditPolicyTest extends TestCase {

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  private function bracket(InMemoryAuditSink $sink, ?IAuditPolicy $policy = null): CorrelationMiddleware {
    return new CorrelationMiddleware(
      new AcmeConfig('acme', '2.1.0'),
      new EventsUnitOfWork(),
      new Redactor(),
      $sink,
      new FixedActorProvider(new Actor(ActorKind::Machine, 'runner-1')),
      $policy,
    );
  }

  public function test_the_attribute_policy_reads_audit_false_and_parameters_false(): void {
    $p = new AttributeAuditPolicy();

    self::assertFalse($p->audits(new Heartbeat()));
    self::assertTrue($p->audits(new AcquireJob()));
    self::assertFalse($p->captureParameters(new AcquireJob()));
    self::assertTrue($p->audits(new CreateTeam()));
    self::assertTrue($p->captureParameters(new CreateTeam()));
  }

  public function test_the_attribute_on_a_parent_class_applies(): void {
    self::assertFalse((new AttributeAuditPolicy())->audits(new Progress()));
  }

  public function test_class_lists_switch_audit_or_parameters_off_by_class_parent_or_interface(): void {
    $p = new AttributeAuditPolicy(notAudited: [IsChatty::class], withoutParameters: [CreateTeam::class]);

    self::assertFalse($p->audits(new ReportProgress()));
    self::assertTrue($p->audits(new CreateTeam()));
    self::assertFalse($p->captureParameters(new CreateTeam()));
  }

  public function test_the_bracket_defaults_to_the_attribute_policy(): void {
    $sink = new InMemoryAuditSink();
    $bracket = $this->bracket($sink);

    $bracket->execute(new Heartbeat(), static fn () => null);
    $bracket->execute(new AcquireJob(), static fn () => null);
    $bracket->execute(new CreateTeam(), static fn () => null);

    self::assertSame([AcquireJob::class, CreateTeam::class], array_map(static fn ($o) => $o->commandName, $sink->opened));
    self::assertSame([], $sink->opened[0]->parameters);
    self::assertSame(['name' => 'Acme'], $sink->opened[1]->parameters);
    self::assertCount(2, $sink->closed);
  }

  public function test_a_host_policy_in_host_defaults_still_wins_over_the_default(): void {
    HostDefaults::provide(IAuditPolicy::class, new AttributeAuditPolicy(notAudited: [CreateTeam::class]));
    $sink = new InMemoryAuditSink();

    $this->bracket($sink)->execute(new CreateTeam(), static fn () => null);

    self::assertSame([], $sink->opened);
  }

  public function test_the_nesting_guard_holds_for_an_unaudited_command(): void {
    $sink = new InMemoryAuditSink();
    $bracket = $this->bracket($sink);

    try {
      $bracket->execute(new CreateTeam(), static fn () => $bracket->execute(new Heartbeat(), static fn () => 'never'));
      self::fail('nested command must throw');
    } catch (CommandDispatchedInsideCommand) {
      // expected: the guard runs before the policy
    }
    self::assertSame([CreateTeam::class], array_map(static fn ($o) => $o->commandName, $sink->opened));
    self::assertSame('error', $sink->closed[0]->status);
  }
}
