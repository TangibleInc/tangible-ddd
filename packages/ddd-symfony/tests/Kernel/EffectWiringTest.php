<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers\RefundToyHandler;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RefundToyCommand;

/**
 * Wave 5 effects on the bundle wiring (CR-W5CC-5, CR-W5CC-6):
 *
 * - E1: an IExternalEffectHandler found by the app's resource loading is
 *   autoconfigured into the command handler locator, and EffectMiddleware
 *   locates it for its IEffectCommand through the bundle's handler mapping;
 * - E2: the journal keeps entry states (a Recorded entry is not recorded
 *   again), unrecorded entries are the operator layer `effect`
 *   (`ddd:ops:list --layer=effect`), and `ddd:ops:effects:invalidate` is
 *   the repair.
 */
final class EffectWiringTest extends KernelTestBase {

  protected static string $variant = 'frozen_clock';

  protected function setUp(): void {
    parent::setUp();
    RefundToyHandler::$performed = [];
    RefundToyHandler::$failRecords = 0;
  }

  private function journal(): ITracksEffectState {
    $journal = self::getContainer()->get('test.effect_journal');
    self::assertInstanceOf(ITracksEffectState::class, $journal);
    return $journal;
  }

  private function clock(): FrozenClock {
    return self::getContainer()->get('tangible_ddd.clock');
  }

  public function test_a_handler_class_effect_is_performed_and_recorded_by_its_handler(): void {
    $result = (new RefundToyCommand('ch_1', 700))->send();

    self::assertInstanceOf(EffectResult::class, $result);
    self::assertSame('re_ch_1', $result->external_ref);
    self::assertSame(['ch_1'], RefundToyHandler::$performed);
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'toy-refund' AND widget_id = 'ch_1'"));
    self::assertSame(EffectState::Recorded, $this->journal()->find_entry('toy-refund:ch_1')?->state);
  }

  public function test_a_recorded_effect_is_neither_performed_nor_recorded_again(): void {
    (new RefundToyCommand('ch_2', 700))->send();

    $again = (new RefundToyCommand('ch_2', 700))->send();

    self::assertSame('re_ch_2', $again->external_ref, 'the journaled result');
    self::assertSame(['ch_2'], RefundToyHandler::$performed);
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'toy-refund'"), 'record() ran once');
  }

  public function test_a_failed_record_leaves_a_performed_entry_that_the_retry_records_without_performing(): void {
    RefundToyHandler::$failRecords = 1;
    try {
      (new RefundToyCommand('ch_3', 700))->send();
      self::fail('expected the record failure');
    } catch (\RuntimeException $e) {
      self::assertStringContainsString('recording the refund failed', $e->getMessage());
    }
    self::assertSame(EffectState::Performed, $this->journal()->find_entry('toy-refund:ch_3')?->state);
    self::assertSame(0, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'toy-refund'"), 'record() rolled back');

    (new RefundToyCommand('ch_3', 700))->send();

    self::assertSame(['ch_3'], RefundToyHandler::$performed, 'perform ran once');
    self::assertSame(EffectState::Recorded, $this->journal()->find_entry('toy-refund:ch_3')?->state);
  }

  public function test_an_unrecorded_effect_is_listed_in_the_effect_layer(): void {
    RefundToyHandler::$failRecords = 1;
    try {
      (new RefundToyCommand('ch_4', 700))->send();
    } catch (\RuntimeException) {
    }
    $this->clock()->advance('+' . (UnrecordedEffects::DEFAULT_AFTER_SECONDS + 1) . ' seconds');

    $items = self::getContainer()->get('test.operator_view')->list(Layer::Effect);

    self::assertCount(1, $items);
    self::assertSame('toy-refund:ch_4', $items[0]->key);
    self::assertSame('sfk', $items[0]->consumer);
    self::assertSame(['invalidate'], $items[0]->repairs);

    $listed = $this->console('ddd:ops:list', ['--layer' => 'effect']);
    self::assertSame(0, $listed->getStatusCode());
    self::assertStringContainsString('toy-refund:ch_4', $listed->getDisplay());
  }

  public function test_effects_invalidate_repairs_an_entry_so_it_performs_again(): void {
    (new RefundToyCommand('ch_5', 700))->send();

    $repair = $this->console('ddd:ops:effects:invalidate', ['key' => ['toy-refund:ch_5'], '--reason' => 'provider lost the refund']);

    self::assertSame(0, $repair->getStatusCode(), $repair->getDisplay());
    self::assertStringContainsString('toy-refund:ch_5', $repair->getDisplay());
    self::assertNull($this->journal()->find_entry('toy-refund:ch_5'));
    self::assertSame('provider lost the refund', $this->db->fetchOne('SELECT invalidation_reason FROM ddd_effect_journal WHERE idempotency_key = ?', ['toy-refund:ch_5']));

    (new RefundToyCommand('ch_5', 700))->send();
    self::assertSame(['ch_5', 'ch_5'], RefundToyHandler::$performed, 'performed again after the repair');
  }

  public function test_effects_invalidate_of_an_unknown_key_fails(): void {
    $repair = $this->console('ddd:ops:effects:invalidate', ['key' => ['toy-refund:none']]);

    self::assertSame(1, $repair->getStatusCode());
    self::assertStringContainsString('toy-refund:none', $repair->getDisplay());
  }
}
