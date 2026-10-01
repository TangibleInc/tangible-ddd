<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\StatementErrors;
use TangibleDDD\Application\Exceptions\DomainEventAfterSealException;
use TangibleDDD\Conformance\BusOptions;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\DispatchNested;
use TangibleDDD\Conformance\Fixtures\IssueReceipt;
use TangibleDDD\Conformance\Fixtures\Receipt;
use TangibleDDD\Conformance\Fixtures\WidgetCreated;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Domain\Events\AlreadyIntegrated;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\NoTransactionBoundary;
use TangibleDDD\Runtime\TransactionFailed;

/**
 * The cmd.* scenarios (register section 4): the command bus, the
 * transaction boundary, the in-transaction reaction and the outbox append,
 * all on the host's one connection; plus audit.sink-fails, which is about
 * the same bus's act bracket (it needs the optional AuditSinkFaults and
 * RecordsSignals seams, CR-CC-1).
 */
abstract class CommandScenarios extends ConformanceTestCase {

  #[Group('cmd.commit-atomic')]
  #[TestDox('cmd.commit-atomic: handler writes a domain row, a reaction stages a fact, commit keeps both')]
  public function test_cmd_commit_atomic(): void {
    $this->reactWithFact();
    $bus = $this->host->commandBus([CreateWidget::class => $this->createWidget()]);

    $bus->handle(new CreateWidget('w-1'));

    self::assertTrue($this->host->scenarioRows()->has('w-1'), 'domain row committed');
    self::assertSame(1, $this->host->outboxAdministration()->stats()['pending'], 'outbox row committed');

    $claims = $this->host->outbox()->claim(10, $this->host->clock()->now(), 60);
    self::assertCount(1, $claims);
    self::assertSame(WidgetRegistered::name(), $claims[0]->record->event_type);
    self::assertSame(WidgetRegistered::integration_action(), $claims[0]->record->integration_action);
    self::assertSame('w-1', $claims[0]->record->payload['widget_id']);
    self::assertNotNull($claims[0]->record->command_id, 'the fact carries its raiser edge (the act)');

    $audit = $this->host->auditTrail();
    self::assertCount(1, $audit);
    self::assertSame(CreateWidget::class, $audit[0]->commandName);
    self::assertSame('success', $audit[0]->status);
    self::assertSame($claims[0]->record->command_id, $audit[0]->commandId);
  }

  #[Group('cmd.commit-failure')]
  #[TestDox('cmd.commit-failure: a failed COMMIT leaves no domain row and no outbox row; the error surfaces and is audited')]
  public function test_cmd_commit_failure(): void {
    $this->reactWithFact();
    $bus = $this->host->commandBus([CreateWidget::class => $this->createWidget()]);
    $this->host->failNextCommit('injected COMMIT failure');

    $thrown = self::catchThrowable(static fn () => $bus->handle(new CreateWidget('w-1')));

    self::assertInstanceOf(TransactionFailed::class, $thrown, 'the COMMIT failure surfaces to the caller');
    self::assertNotNull($thrown->getPrevious(), 'the driver error is kept as previous');
    self::assertFalse($this->host->scenarioRows()->has('w-1'), 'no domain row');
    self::assertSame(0, $this->host->scenarioRows()->count());
    self::assertSame(0, $this->outboxRowCount(), 'no outbox row');

    $audit = $this->host->auditTrail();
    self::assertCount(1, $audit);
    self::assertSame('error', $audit[0]->status);
    self::assertSame(TransactionFailed::class, $audit[0]->errorType);

    if ($this->host instanceof StatementErrors) {
      $this->workSwallowsAStatementError($this->host);
    }
  }

  /**
   * CR sf-7 (wave-2 notes): the handler catches a statement error and
   * returns normally. Engines that abort the transaction (Postgres, 25P02)
   * answer the COMMIT with ROLLBACK and no error; the boundary must not
   * report that as success. Either the work committed in full, or the
   * caller gets TransactionFailed and nothing persisted. Never "success"
   * with the rows gone.
   */
  private function workSwallowsAStatementError(StatementErrors $statements): void {
    $swallowed = null;
    $bus = $this->host->commandBus([CreateWidget::class => function (CreateWidget $c) use ($statements, &$swallowed): void {
      $this->host->scenarioRows()->insert($c->widget_id, 'created');
      $this->host->events()->record(new WidgetCreated($c->widget_id));
      try {
        $statements->runFailingStatement();
      } catch (\Throwable $e) {
        $swallowed = $e;
      }
    }]);

    $thrown = self::catchThrowable(static fn () => $bus->handle(new CreateWidget('w-swallowed')));

    self::assertNotNull($swallowed, 'the statement failed inside the handler');
    $audit = $this->host->auditTrail();
    if ($thrown === null) {
      self::assertTrue($this->host->scenarioRows()->has('w-swallowed'), 'reported success: the domain row committed');
      self::assertSame(1, $this->outboxRowCount(), 'reported success: the outbox row committed');
      self::assertSame('success', end($audit)->status);
    } else {
      self::assertInstanceOf(TransactionFailed::class, $thrown, 'an aborted transaction surfaces as TransactionFailed');
      self::assertFalse($this->host->scenarioRows()->has('w-swallowed'), 'no domain row');
      self::assertSame(0, $this->outboxRowCount(), 'no outbox row');
      self::assertSame('error', end($audit)->status);
    }
  }

  #[Group('cmd.reaction-throws')]
  #[TestDox('cmd.reaction-throws: an in-transaction reaction that throws rolls everything back; nothing is relayed')]
  public function test_cmd_reaction_throws(): void {
    $this->reactWithFact();
    $boom = new \DomainException('reaction failed');
    $this->host->listen(WidgetCreated::class, static function () use ($boom): void { throw $boom; }, 20);
    $bus = $this->host->commandBus([CreateWidget::class => $this->createWidget()]);

    $thrown = self::catchThrowable(static fn () => $bus->handle(new CreateWidget('w-1')));

    self::assertSame($boom, $thrown, 'the reaction\'s own exception surfaces unchanged');
    self::assertSame(0, $this->host->scenarioRows()->count(), 'domain row rolled back');
    self::assertSame(0, $this->outboxRowCount(), 'staged fact rolled back');

    $report = $this->host->relayOnce();
    self::assertSame([], $report->claimed);
    self::assertSame([], $this->host->transported(), 'nothing relayed');
  }

  #[Group('cmd.no-boundary')]
  #[TestDox('cmd.no-boundary: an ITransactionalCommand with no boundary fails with NoTransactionBoundary before the handler runs')]
  public function test_cmd_no_boundary(): void {
    $ran = false;
    $bus = $this->host->commandBus(
      [CreateWidget::class => function (CreateWidget $c) use (&$ran): void {
        $ran = true;
        $this->host->scenarioRows()->insert($c->widget_id, 'created');
      }],
      new BusOptions(withBoundary: false),
    );

    $thrown = self::catchThrowable(static fn () => $bus->handle(new CreateWidget('w-1')));

    self::assertInstanceOf(NoTransactionBoundary::class, $thrown);
    self::assertFalse($ran, 'handler never ran');
    self::assertSame(0, $this->host->scenarioRows()->count());
  }

  #[Group('cmd.nested-rejected')]
  #[TestDox('cmd.nested-rejected: with an outer transaction open and policy Reject, the command is refused and the outer transaction is untouched')]
  public function test_cmd_nested_rejected(): void {
    $ran = false;
    $bus = $this->host->commandBus([CreateWidget::class => function (CreateWidget $c) use (&$ran): void {
      $ran = true;
      $this->host->scenarioRows()->insert($c->widget_id, 'created');
    }]);
    $caught = null;

    $this->host->boundary()->run(function () use ($bus, &$caught): void {
      $this->host->scenarioRows()->insert('outer', 'outer');
      try {
        $bus->handle(new CreateWidget('inner'));
      } catch (NestedTransactionRejected $e) {
        $caught = $e;
      }
      $this->host->scenarioRows()->insert('outer-after', 'outer');
    });

    self::assertInstanceOf(NestedTransactionRejected::class, $caught);
    self::assertFalse($ran, 'the nested handler never ran');
    self::assertTrue($this->host->scenarioRows()->has('outer'), 'outer work before the attempt committed');
    self::assertTrue($this->host->scenarioRows()->has('outer-after'), 'the outer transaction stayed usable');
    self::assertFalse($this->host->scenarioRows()->has('inner'));
    self::assertFalse($this->host->boundary()->isActive());
  }

  #[Group('cmd.guards-without-audit')]
  #[TestDox('cmd.guards-without-audit: nested command, post-seal event and re-raised fact still throw with audit off')]
  public function test_cmd_guards_without_audit(): void {
    $options = new BusOptions(audit: false);
    $bus = null;
    $handlers = [
      CreateWidget::class => $this->createWidget(),
      DispatchNested::class => static function (DispatchNested $c) use (&$bus): void {
        $bus->handle(new CreateWidget($c->widget_id));
      },
    ];
    $bus = $this->host->commandBus($handlers, $options);

    // 1. No command inside a command.
    $nested = self::catchThrowable(static fn () => $bus->handle(new DispatchNested('nested')));
    self::assertInstanceOf(CommandDispatchedInsideCommand::class, $nested);
    self::assertFalse($this->host->scenarioRows()->has('nested'));

    // 2. A published fact instance cannot be raised again.
    $this->reactWithFact();
    $published = null;
    $this->host->listen(WidgetRegistered::class, static function (WidgetRegistered $e) use (&$published): void {
      $published = $e;
    });
    $bus->handle(new CreateWidget('w-1'));
    self::assertInstanceOf(WidgetRegistered::class, $published);

    $reraise = $this->host->commandBus([CreateWidget::class => function (CreateWidget $c) use (&$published): void {
      $this->host->scenarioRows()->insert($c->widget_id, 'created');
      $this->host->events()->record($published);
    }], $options);
    self::assertInstanceOf(AlreadyIntegrated::class, self::catchThrowable(static fn () => $reraise->handle(new CreateWidget('w-2'))));
    self::assertFalse($this->host->scenarioRows()->has('w-2'));

    // 3. No plain domain event past the seal.
    $this->host->listen(WidgetCreated::class, function (WidgetCreated $e): void {
      if ($e->widget_id === 'seal-probe') {
        $this->host->events()->record(new WidgetCreated('recorded-after-seal'));
      }
    });
    self::assertInstanceOf(DomainEventAfterSealException::class, self::catchThrowable(static fn () => $bus->handle(new CreateWidget('seal-probe'))));
    self::assertFalse($this->host->scenarioRows()->has('seal-probe'));

    self::assertSame(['w-1'], $this->rowIds(['nested', 'w-1', 'w-2', 'seal-probe']), 'only the clean command committed');
    self::assertSame([], $this->host->auditTrail(), 'audit is off: nothing written, guards still held');
  }

  #[Group('cmd.return-value')]
  #[TestDox('cmd.return-value: a command returns its DTO unchanged through every middleware (D11)')]
  public function test_cmd_return_value(): void {
    $receipt = new Receipt('w-1', 'R-0001');
    $bus = $this->host->commandBus([IssueReceipt::class => function (IssueReceipt $c) use ($receipt): Receipt {
      $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
      $this->host->events()->record(new WidgetCreated($c->widget_id));
      return $receipt;
    }]);

    $result = $bus->handle(new IssueReceipt('w-1'));

    self::assertSame($receipt, $result);
    self::assertTrue($this->host->scenarioRows()->has('w-1'));
  }

  #[Group('audit.sink-fails')]
  #[TestDox('audit.sink-fails: the audit sink throws after the domain commit; the business result stands and AuditSinkFailed is emitted')]
  public function test_audit_sink_fails(): void {
    if (!$this->host instanceof AuditSinkFaults || !$this->host instanceof RecordsSignals) {
      $this->skipForChangeRequest('CR-CC-1', 'the host fixture implements neither AuditSinkFaults nor RecordsSignals yet');
    }
    $this->reactWithFact();
    $receipt = new Receipt('w-1', 'R-0001');
    $bus = $this->host->commandBus([
      IssueReceipt::class => function (IssueReceipt $c) use ($receipt): Receipt {
        $this->host->scenarioRows()->insert($c->widget_id, 'receipted');
        $this->host->events()->record(new WidgetCreated($c->widget_id));
        return $receipt;
      },
      CreateWidget::class => static function (): void {
        throw new \DomainException('business failure');
      },
    ]);

    // 1. Success: the close fails after commit; nothing the caller sees changes.
    $this->host->failNextAuditClose('audit store down');
    $result = $bus->handle(new IssueReceipt('w-1'));

    self::assertSame($receipt, $result, 'the command result passes through');
    self::assertTrue($this->host->scenarioRows()->has('w-1'), 'domain row committed');
    $claims = $this->host->outbox()->claim(10, $this->host->clock()->now(), 60);
    self::assertCount(1, $claims, 'outbox row committed');
    $commandId = $claims[0]->record->command_id;
    self::assertNotNull($commandId);
    self::assertSame([], $this->host->auditTrail(), 'the row was never closed');

    $failed = $this->auditSinkFailures();
    self::assertCount(1, $failed, 'one AuditSinkFailed signal');
    self::assertSame('close', $failed[0]->phase);
    self::assertSame($commandId, $failed[0]->subject(), 'subject: the command whose row failed');
    self::assertSame($claims[0]->record->correlation_id, $failed[0]->correlation_id(), 'in the command\'s story');
    self::assertStringContainsString('audit store down', $failed[0]->error);

    // 2. Failure: the business exception, not the sink's, reaches the caller.
    $this->host->failNextAuditClose('audit store still down');
    $thrown = self::catchThrowable(static fn () => $bus->handle(new CreateWidget('w-2')));

    self::assertInstanceOf(\DomainException::class, $thrown);
    self::assertSame('business failure', $thrown->getMessage());
    self::assertCount(2, $this->auditSinkFailures());
  }

  // ── helpers ──────────────────────────────────────────────────────────────

  /** @return list<AuditSinkFailed> */
  private function auditSinkFailures(): array {
    \assert($this->host instanceof RecordsSignals);
    return array_values(array_filter($this->host->signals(), static fn ($s) => $s instanceof AuditSinkFailed));
  }

  /** The standard handler: write the domain row, record the domain event. */
  protected function createWidget(): \Closure {
    return function (CreateWidget $c): void {
      $this->host->scenarioRows()->insert($c->widget_id, 'created');
      $this->host->events()->record(new WidgetCreated($c->widget_id));
    };
  }

  /** The standard reaction: WidgetCreated stages the WidgetRegistered fact (past the seal). */
  protected function reactWithFact(int $delaySeconds = 0): void {
    $this->host->listen(WidgetCreated::class, function (WidgetCreated $e) use ($delaySeconds): void {
      if ($e->widget_id !== 'seal-probe') {
        $this->host->events()->record(new WidgetRegistered($e->widget_id, $delaySeconds));
      }
    });
  }

  protected function outboxRowCount(): int {
    $stats = $this->host->outboxAdministration()->stats();
    unset($stats['dead_letters']);
    return array_sum($stats);
  }

  /** @param list<string> $candidates @return list<string> the candidates present, in order */
  private function rowIds(array $candidates): array {
    return array_values(array_filter($candidates, fn (string $id) => $this->host->scenarioRows()->has($id)));
  }
}
