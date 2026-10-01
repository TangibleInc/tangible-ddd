<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\IssueReceipt;
use TangibleDDD\Conformance\Fixtures\Receipt;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\HostFixture;

/**
 * audit.sink-fails on wp (register section 4: "audit sink throws after
 * domain commit → business result committed; signal emitted").
 *
 * ddd-conformance has no shared scenario method for this id yet (mem and wp
 * are both due in wave 2), so the wp host carries it here, written against
 * HostFixture plus two wp seams (WpHostFixture::failNextAuditWrite(),
 * signals()). Change request WPC-4 asks the conformance owner to lift it
 * into a shared AuditScenarios case with those two seams on HostFixture.
 *
 * The failure is injected inside wpdb (Support\WpdbFaults on the `query`
 * filter), so the REAL WpdbAuditSink, i.e. the 0.6 command_audit_finalise()
 * / command_audit_preflight(), is what throws.
 */
#[Group('wp')]
final class WpAuditConformance extends ConformanceTestCase {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }

  private function wp(): WpHostFixture {
    self::assertInstanceOf(WpHostFixture::class, $this->host);
    return $this->host;
  }

  #[Group('audit.sink-fails')]
  #[TestDox('audit.sink-fails: the audit sink throws after the domain commit; the business result stays committed and a signal is emitted')]
  public function test_audit_sink_fails(): void {
    $receipt = new Receipt('w-1', 'R-0001');
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c) use ($receipt): Receipt {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      $this->host->events()->record(new WidgetRegistered($c->widget_id));
      return $receipt;
    }]);
    $this->wp()->failNextAuditWrite('close');

    $result = $bus->handle(new IssueReceipt('w-1'));

    self::assertSame($receipt, $result, 'the command result passes through untouched');
    self::assertTrue($this->host->scenarioRows()->has('w-1'), 'domain row committed');
    self::assertSame(1, $this->host->outboxAdministration()->stats()['pending'], 'outbox row committed');

    $signals = $this->wp()->signals();
    self::assertCount(1, $signals, 'exactly one AuditSinkFailed signal');
    self::assertInstanceOf(AuditSinkFailed::class, $signals[0]);
    self::assertSame('close', $signals[0]->phase);
    self::assertStringContainsString('audit sink close failed (injected)', $signals[0]->error);

    $commandId = (string) $signals[0]->subject();
    $row = $this->wp()->auditRow($commandId);
    self::assertNotNull($row, 'the opened audit row exists');
    self::assertSame('in_progress', $row['status'], 'the close that failed left the row visibly incomplete');
    self::assertSame([], $this->host->auditTrail(), 'no closed audit row');
  }

  #[TestDox('audit.sink-fails (open): a failing preflight write does not stop the command; signal phase open, no row')]
  public function test_audit_sink_fails_on_open(): void {
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c): Receipt {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      return new Receipt($c->widget_id, 'R-0002');
    }]);
    $this->wp()->failNextAuditWrite('open');

    $result = $bus->handle(new IssueReceipt('w-2'));

    self::assertSame('R-0002', $result->number);
    self::assertTrue($this->host->scenarioRows()->has('w-2'));
    $signals = $this->wp()->signals();
    self::assertCount(1, $signals);
    self::assertSame('open', $signals[0]->phase);
    self::assertNull($this->wp()->auditRow((string) $signals[0]->subject()), 'no row: the open never happened, so no close is attempted');
  }

  #[TestDox('audit.sink-fails (error path): when the command fails AND the close write fails, the command\'s own exception surfaces')]
  public function test_audit_sink_failure_never_replaces_the_command_error(): void {
    $boom = new \DomainException('handler failed');
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c) use ($boom): never {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      throw $boom;
    }]);
    $this->wp()->failNextAuditWrite('close');

    $thrown = self::catchThrowable(static fn () => $bus->handle(new IssueReceipt('w-3')));

    self::assertSame($boom, $thrown);
    self::assertFalse($this->host->scenarioRows()->has('w-3'), 'rolled back');
    self::assertCount(1, $this->wp()->signals());
  }
}
