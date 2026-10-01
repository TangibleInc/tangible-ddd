<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\EffectStateHost;
use TangibleDDD\Conformance\Fixtures\Effects\ChargeOnWidgetRegistered;
use TangibleDDD\Conformance\Fixtures\Effects\ChargeWidget;
use TangibleDDD\Conformance\Fixtures\Effects\EffectLedger;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;

/**
 * E2: performed, then recorded (wave 5, CR-W5C5-2; core CR-W5CC-6). An
 * effect whose perform() succeeded and whose record() failed is journaled
 * Performed and, once older than the UnrecordedEffects threshold, is an
 * operator item of the `effect` layer; the retry reuses the journaled
 * result and records once, after which the entry is Recorded and a further
 * dispatch neither performs nor records again. Needs EffectStateHost.
 *
 * Same wiring as effect.journal-reuse: WidgetRegistered → ChargeWidget
 * through the core SubscriptionRegistrar on the host's registry and
 * delivery runner, ChargeWidget through the host bus with EffectMiddleware.
 */
abstract class EffectStateScenarios extends ConformanceTestCase {

  protected function setUp(): void {
    EffectLedger::reset();
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    EffectLedger::reset();
  }

  #[Group('effect.performed-not-recorded')]
  #[TestDox('effect.performed-not-recorded: perform ok and record throws; the entry is Performed and, past the threshold, an effect-layer operator item; the retry reuses the result and records once; then it is Recorded and neither performs nor records again')]
  public function test_effect_performed_not_recorded(): void {
    $effects = $this->effects();
    $journal = $effects->effect_journal();
    if (!$journal instanceof ITracksEffectState) {
      $this->skip_for('CR-W5CC-6', 'the host effect journal does not implement ITracksEffectState yet');
    }
    $rows = $this->host->rows();
    EffectLedger::$rows = $rows;
    EffectLedger::$bus = $effects->effect_bus([]);
    (new SubscriptionRegistrar($this->host->subscriptions()))->register_listener(new ChargeOnWidgetRegistered());
    $key = ChargeWidget::key_for('w-1');

    // 1. perform succeeds, record throws: journaled Performed, not recorded.
    EffectLedger::fail_record('w-1', 1);
    $performedAt = $this->host->clock()->now();
    $wrapped = self::wrap(new WidgetRegistered('w-1'), Uuid::v4());
    self::assertTrue($this->host->deliver(WidgetRegistered::class, $wrapped)->needs_retry(), 'the subscriber failed and has budget left');
    self::assertSame(1, EffectLedger::$performs['w-1'] ?? 0);
    self::assertSame(0, EffectLedger::$records['w-1'] ?? 0);
    self::assertFalse($rows->has('charged:w-1:ch-w-1-1'), 'record() rolled back');

    $entry = $this->entry($journal, $key);
    self::assertSame(EffectState::Performed, $entry->state);
    self::assertFalse($entry->is_recorded());
    self::assertNull($entry->recorded_at);
    self::assertSame('ch-w-1-1', $entry->result->external_ref);
    self::assertSame($performedAt->getTimestamp(), $entry->performed_at->getTimestamp(), 'performed_at is the host clock');

    // 2. Visible as unrecorded, once older than the threshold.
    self::assertSame([], $this->unrecorded(), 'not an operator item while the record may still follow');
    $this->host->advance_clock(UnrecordedEffects::DEFAULT_AFTER_SECONDS + 1);
    $items = $effects->operator_view()->list(Layer::Effect);
    self::assertCount(1, $items, 'one effect-layer item');
    self::assertSame(Layer::Effect, $items[0]->layer);
    self::assertSame($key, $items[0]->key, 'keyed by the idempotency key');
    self::assertSame(['invalidate'], $items[0]->repairs);
    self::assertSame($performedAt->getTimestamp(), $items[0]->first_seen?->getTimestamp(), 'first seen when performed');
    self::assertStringContainsString('not recorded', (string) $items[0]->last_error);
    self::assertSame([$key], array_map(
      static fn (EffectEntry $e) => $e->key,
      $journal->find_unrecorded($this->host->clock()->now(), 10),
    ));

    // 3. The retry reuses the journaled result and records once.
    self::assertTrue($this->host->deliver(WidgetRegistered::class, $wrapped)->is_complete());
    self::assertSame(1, EffectLedger::$performs['w-1'], 'perform not called again');
    self::assertSame(1, EffectLedger::$records['w-1'] ?? 0, 'recorded once');
    self::assertTrue($rows->has('charged:w-1:ch-w-1-1'), 'with the journaled result');
    $entry = $this->entry($journal, $key);
    self::assertSame(EffectState::Recorded, $entry->state);
    self::assertTrue($entry->is_recorded());
    self::assertNotNull($entry->recorded_at);
    self::assertSame([], $this->unrecorded(), 'no longer an operator item');
    self::assertSame([], $journal->find_unrecorded($this->host->clock()->now(), 10));

    // 4. Recorded: a dispatch under a new command id neither performs nor records.
    $result = EffectLedger::bus()->handle(new ChargeWidget('w-1'));
    self::assertSame('ch-w-1-1', $result->external_ref, 'the journaled result');
    self::assertSame(1, EffectLedger::$performs['w-1']);
    self::assertSame(1, EffectLedger::$records['w-1'], 'record() did not run again');
    self::assertSame(EffectState::Recorded, $this->entry($journal, $key)->state);
  }

  protected function effects(): EffectStateHost {
    if (!$this->host instanceof EffectStateHost) {
      $this->skip_for('CR-W5C5-2', 'the host fixture does not implement EffectStateHost yet');
    }
    return $this->host;
  }

  private function entry(ITracksEffectState $journal, string $key): EffectEntry {
    $entry = $journal->find_entry($key);
    self::assertNotNull($entry, "journal entry $key");
    return $entry;
  }

  /** @return list<string> keys of the effect-layer operator items */
  private function unrecorded(): array {
    return array_map(static fn (OperatorItem $i) => $i->key, $this->effects()->operator_view()->list(Layer::Effect));
  }
}
