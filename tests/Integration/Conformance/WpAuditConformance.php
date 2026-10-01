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
 * domain commit → business result committed; signal emitted"; due on wp in
 * wave 2).
 *
 * This branch's base has no shared scenario method for the id, so the wp
 * host carries it here, against HostFixture plus the two seams that
 * wave2/conformance-cleanup proposes as optional interfaces (CR-CC-1:
 * failNextAuditClose(), signals(); WpHostFixture already has both shapes).
 * After that branch merges, its shared CommandScenarios::test_audit_sink_fails
 * also runs on wp once WpHostFixture declares the two interfaces (WPC-4);
 * the open-phase and error-path cases below stay wp-specific.
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
  #[TestDox('audit.sink-fails: the audit sink throws after the domain commit; the business result stays committed and AuditSinkFailed is emitted')]
  public function test_audit_sink_fails(): void {
    $receipt = new Receipt('w-1', 'R-0001');
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c) use ($receipt): Receipt {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      $this->host->events()->record(new WidgetRegistered($c->widget_id));
      return $receipt;
    }]);
    $this->wp()->failNextAuditClose('audit store down');

    $result = $bus->handle(new IssueReceipt('w-1'));

    self::assertSame($receipt, $result, 'the command result passes through untouched');
    self::assertTrue($this->host->scenarioRows()->has('w-1'), 'domain row committed');
    $claims = $this->host->outbox()->claim(10, $this->host->clock()->now(), 60);
    self::assertCount(1, $claims, 'outbox row committed');
    $commandId = $claims[0]->record->command_id;
    self::assertNotNull($commandId);

    $signals = $this->wp()->signals();
    self::assertCount(1, $signals, 'exactly one signal');
    self::assertInstanceOf(AuditSinkFailed::class, $signals[0]);
    self::assertSame('close', $signals[0]->phase);
    self::assertSame($commandId, $signals[0]->subject(), 'subject: the command whose row failed');
    self::assertSame($claims[0]->record->correlation_id, $signals[0]->correlation_id(), 'in the command\'s story');
    self::assertStringContainsString('audit store down', $signals[0]->error);

    $row = $this->wp()->auditRow($commandId);
    self::assertNotNull($row, 'the opened 0.6 audit row exists');
    self::assertSame('in_progress', $row['status'], 'the failed close left the row visibly incomplete');
    self::assertSame([], $this->host->auditTrail(), 'no closed audit row');
  }

  #[TestDox('audit.sink-fails (open): a failing preflight write does not stop the command; signal phase open, no row, no close attempted')]
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
    self::assertInstanceOf(AuditSinkFailed::class, $signals[0]);
    self::assertSame('open', $signals[0]->phase);
    self::assertNull($this->wp()->auditRow((string) $signals[0]->subject()));
  }

  #[TestDox('audit.sink-fails (error path): when the command fails and the close write fails, the command\'s own exception surfaces')]
  public function test_audit_sink_failure_never_replaces_the_command_error(): void {
    $boom = new \DomainException('business failure');
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c) use ($boom): never {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      throw $boom;
    }]);
    $this->wp()->failNextAuditClose('audit store still down');

    $thrown = self::catchThrowable(static fn () => $bus->handle(new IssueReceipt('w-3')));

    self::assertSame($boom, $thrown);
    self::assertFalse($this->host->scenarioRows()->has('w-3'), 'rolled back');
    self::assertCount(1, $this->wp()->signals());
    self::assertSame('close', $this->wp()->signals()[0]->phase);
  }
}
