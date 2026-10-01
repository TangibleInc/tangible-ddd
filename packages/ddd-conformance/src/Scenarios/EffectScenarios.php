<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\EffectHost;
use TangibleDDD\Conformance\Fixtures\Effects\ChargeFailed;
use TangibleDDD\Conformance\Fixtures\Effects\ChargeOnWidgetRegistered;
use TangibleDDD\Conformance\Fixtures\Effects\ChargeWidget;
use TangibleDDD\Conformance\Fixtures\Effects\EffectLedger;
use TangibleDDD\Conformance\Fixtures\Effects\RepairCharge;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;

/**
 * D1 ExternalEffect (register section 4 `effect.journal-reuse`, 3.8 D1,
 * 5.1). Needs EffectHost (CR-W4C4-2).
 *
 * WidgetRegistered → ChargeWidget through the core SubscriptionRegistrar on
 * the host's subscription registry and delivery runner; ChargeWidget runs
 * through the host bus with EffectMiddleware.
 */
abstract class EffectScenarios extends ConformanceTestCase {

  /** Upper bound on deliveries while exhausting a budget (the core default is 5). */
  private const MAX_DELIVERIES = 20;

  protected function setUp(): void {
    EffectLedger::reset();
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    EffectLedger::reset();
  }

  #[Group('effect.journal-reuse')]
  #[TestDox('effect.journal-reuse: perform ok and record throws, the redelivery records the journaled result without performing; at the ledger budget the core invoker commits the failure command once; a repair that invalidates in its transaction performs again')]
  public function test_effect_journal_reuse(): void {
    $effects = $this->effects();
    $journal = $effects->effectJournal();
    $rows = $this->host->scenarioRows();
    EffectLedger::$rows = $rows;
    EffectLedger::$bus = $effects->effectBus([
      ChargeFailed::class => static function (ChargeFailed $c) use ($rows): void {
        $rows->insert("charge-failed:{$c->widget_id}", $c->reason);
      },
      RepairCharge::class => static function (RepairCharge $c) use ($journal): void {
        $journal->invalidate(ChargeWidget::keyFor($c->widget_id), $c->reason);
        if ($c->abort) {
          throw new \RuntimeException('repair aborted after invalidate');
        }
      },
    ]);
    (new SubscriptionRegistrar($this->host->subscriptions()))->registerListener(new ChargeOnWidgetRegistered());

    // 1. perform succeeds, record throws: the result is journaled anyway.
    EffectLedger::failRecord('w-1', 1);
    $wrapped = self::wrap(new WidgetRegistered('w-1'), Uuid::v4());
    $first = $this->host->deliver(WidgetRegistered::class, $wrapped);
    self::assertTrue($first->needsRetry(), 'the subscriber failed and has budget left');
    self::assertSame(1, EffectLedger::$performs['w-1'] ?? 0);
    self::assertInstanceOf(EffectResult::class, $journal->find(ChargeWidget::keyFor('w-1')), 'journaled outside the rolled-back transaction');
    self::assertFalse($rows->has('charged:w-1:ch-w-1-1'), 'record() rolled back');

    // The redelivery records the journaled result; perform is not called again.
    $second = $this->host->deliver(WidgetRegistered::class, $wrapped);
    self::assertTrue($second->isComplete());
    self::assertSame(1, EffectLedger::$performs['w-1'], 'perform not called again');
    self::assertTrue($rows->has('charged:w-1:ch-w-1-1'), 'record ran with the journaled result');

    // A dispatch under a new command id does not bypass the journal either.
    $result = EffectLedger::bus()->handle(new ChargeWidget('w-1'));
    self::assertInstanceOf(EffectResult::class, $result, 'the bus returns the EffectResult (D11)');
    self::assertSame('ch-w-1-1', $result->externalRef);
    self::assertSame(1, EffectLedger::$performs['w-1']);

    // 2. record keeps failing: the subscriber's ledger attempts reach the
    //    budget and the core invoker commits the failure command once.
    EffectLedger::failRecord('w-2', PHP_INT_MAX);
    $eventId = Uuid::v4();
    $wrapped = self::wrap(new WidgetRegistered('w-2'), $eventId);
    $deliveries = 0;
    do {
      $outcome = $this->host->deliver(WidgetRegistered::class, $wrapped);
      $deliveries++;
    } while ($outcome->needsRetry() && $deliveries < self::MAX_DELIVERIES);
    self::assertFalse($outcome->needsRetry(), "the budget was exhausted within $deliveries deliveries");
    self::assertGreaterThan(1, $deliveries);
    self::assertSame(1, EffectLedger::$performs['w-2'], 'every retry reused the journaled result');
    self::assertTrue($rows->has('charge-failed:w-2'), 'the failure command committed');
    self::assertSame(
      [DeterministicCommandId::forFact($eventId, ChargeOnWidgetRegistered::SUBSCRIBER_ID . '#failure')],
      EffectLedger::$failureSends,
      'sent once, under uuid5(event_id, "{subscriber}#failure")',
    );
    $this->host->deliver(WidgetRegistered::class, $wrapped);
    self::assertCount(1, EffectLedger::$failureSends, 'a later delivery does not fire it again');
    self::assertSame(1, EffectLedger::$performs['w-2']);

    // 3. The repair: invalidate inside the repair command's transaction.
    $aborted = self::catchThrowable(static fn () => EffectLedger::bus()->handle(new RepairCharge('w-1', 'customer deleted upstream', abort: true)));
    self::assertInstanceOf(\RuntimeException::class, $aborted);
    self::assertNotNull($journal->find(ChargeWidget::keyFor('w-1')), 'a rolled-back repair leaves the entry');
    EffectLedger::bus()->handle(new ChargeWidget('w-1'));
    self::assertSame(1, EffectLedger::$performs['w-1'], 'still journaled');

    EffectLedger::bus()->handle(new RepairCharge('w-1', 'customer deleted upstream'));
    self::assertNull($journal->find(ChargeWidget::keyFor('w-1')), 'the repair invalidated the entry');
    $again = EffectLedger::bus()->handle(new ChargeWidget('w-1'));
    self::assertSame(2, EffectLedger::$performs['w-1'], 'performed again after the repair');
    self::assertSame('ch-w-1-2', $again->externalRef);
    self::assertTrue($rows->has('charged:w-1:ch-w-1-2'));
    self::assertSame('ch-w-1-2', $journal->find(ChargeWidget::keyFor('w-1'))?->externalRef, 'the new result is journaled');
  }

  protected function effects(): EffectHost {
    if (!$this->host instanceof EffectHost) {
      $this->skipForChangeRequest('CR-W4C4-2', 'the host fixture does not implement EffectHost yet');
    }
    return $this->host;
  }
}
