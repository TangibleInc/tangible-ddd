<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\PdoEffectJournal;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\Layer;

/** D1: IEffectJournal on `{prefix}ddd_effect_journal`, MySQL 8. */
abstract class PdoEffectJournalCases extends PdoTestCase {

  private FrozenClock $clock;
  private PdoEffectJournal $journal;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    $this->journal = new PdoEffectJournal($this->db, self::PREFIX, $this->clock);
  }

  public function test_it_is_the_core_port(): void {
    self::assertInstanceOf(IEffectJournal::class, $this->journal);
  }

  public function test_an_unknown_key_has_no_result(): void {
    self::assertNull($this->journal->find('stripe:customer:1'));
  }

  public function test_a_stored_result_is_found_with_its_data_and_external_ref(): void {
    $data = ['customer' => 'cus_1', 'amount' => 1200, 'ratio' => 1.0, 'live' => false, 'note' => null, 'name' => "naïve \u{1F600}", 'lines' => [['sku' => 'a', 'qty' => 2]], 'z' => 1, 'a' => 2];
    $this->journal->store('stripe:customer:1', new EffectResult($data, 'cus_1'));

    $found = (new PdoEffectJournal($this->otherConnection(), self::PREFIX, $this->clock))->find('stripe:customer:1');
    self::assertNotNull($found);
    self::assertSame($data, $found->data, 'exact round trip, key order included');
    self::assertSame('cus_1', $found->external_ref);
    self::assertSame('2026-10-01 12:00:00.000000', $this->row('ddd_effect_journal', 'idempotency_key = ?', ['stripe:customer:1'])['performed_at']);
  }

  public function test_an_empty_result_round_trips(): void {
    $this->journal->store('k', new EffectResult());

    $found = $this->journal->find('k');
    self::assertNotNull($found);
    self::assertSame([], $found->data);
    self::assertNull($found->external_ref);
  }

  public function test_storing_an_existing_key_overwrites_it(): void {
    $this->journal->store('k', new EffectResult(['v' => 1], 'r1'));
    $this->journal->store('k', new EffectResult(['v' => 2], 'r2'));

    self::assertSame(['v' => 2], $this->journal->find('k')?->data);
    self::assertSame('r2', $this->journal->find('k')?->external_ref);
    self::assertSame(1, $this->countRows('ddd_effect_journal'));
  }

  public function test_invalidate_makes_the_effect_perform_again_and_keeps_the_reason(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->clock->advance('PT5M');

    $this->journal->invalidate('k', 'operator: customer deleted in Stripe');
    $this->journal->invalidate('k', 'again');

    self::assertNull($this->journal->find('k'));
    $row = $this->row('ddd_effect_journal', 'idempotency_key = ?', ['k']);
    self::assertSame('operator: customer deleted in Stripe', $row['invalidation_reason'], 'an invalidated entry is not invalidated twice');
    self::assertSame(1, (int) $row['invalidations']);
    self::assertSame('2026-10-01 12:05:00.000000', $row['invalidated_at']);
  }

  public function test_invalidating_an_unknown_key_is_a_no_op(): void {
    $this->journal->invalidate('never-performed', 'why not');

    self::assertNull($this->journal->find('never-performed'));
    self::assertSame(0, $this->countRows('ddd_effect_journal'));
  }

  public function test_a_store_after_invalidate_is_found_again_and_keeps_the_counter(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->journal->invalidate('k', 'repair');

    $this->journal->store('k', new EffectResult(['v' => 2]));

    self::assertSame(['v' => 2], $this->journal->find('k')?->data);
    $this->journal->invalidate('k', 'second repair');
    self::assertSame(2, (int) $this->row('ddd_effect_journal', 'idempotency_key = ?', ['k'])['invalidations']);
  }

  public function test_invalidate_commits_or_rolls_back_with_the_repair_command(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $boundary = new PdoTransactionBoundary($this->db);

    try {
      $boundary->run(function (): void {
        $this->journal->invalidate('k', 'repair that fails');
        throw new \DomainException('the repair command failed');
      });
    } catch (\DomainException) {
    }
    self::assertSame(['v' => 1], $this->journal->find('k')?->data, 'a rolled-back repair leaves the journal entry');

    $boundary->run(fn () => $this->journal->invalidate('k', 'repair'));
    self::assertNull($this->journal->find('k'));
  }

  public function test_a_key_longer_than_the_column_is_refused(): void {
    $this->expectException(\RuntimeException::class);
    $this->journal->store(str_repeat('k', 192), new EffectResult());
  }

  public function test_a_storage_failure_is_a_runtime_exception(): void {
    $journal = new PdoEffectJournal($this->db, 'missing_', $this->clock);

    $this->expectException(\RuntimeException::class);
    $journal->find('k');
  }

  public function test_a_corrupt_row_is_a_runtime_exception(): void {
    $this->db->execute('INSERT INTO `' . $this->table('ddd_effect_journal') . "` (idempotency_key, result_json, performed_at) VALUES ('k', '\"text\"', '2026-10-01 12:00:00')");

    $this->expectException(\RuntimeException::class);
    $this->journal->find('k');
  }

  // ── E2 (wave 5): entry states, ITracksEffectState (schema 010) ─────────────

  public function test_it_tracks_entry_states(): void {
    self::assertInstanceOf(ITracksEffectState::class, $this->journal);
  }

  public function test_a_stored_entry_is_performed_and_not_recorded(): void {
    $this->journal->store('k', new EffectResult(['v' => 1], 'r1'));

    $entry = $this->journal->find_entry('k');
    self::assertNotNull($entry);
    self::assertSame('k', $entry->key);
    self::assertSame(EffectState::Performed, $entry->state);
    self::assertSame(['v' => 1], $entry->result->data);
    self::assertSame('r1', $entry->result->external_ref);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $entry->performed_at);
    self::assertNull($entry->recorded_at);
    self::assertNull($this->journal->find_entry('unknown'));
  }

  public function test_mark_recorded_sets_the_state_and_the_time(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->clock->advance('PT5S');

    $this->journal->mark_recorded('k');
    $this->journal->mark_recorded('k');

    $entry = (new PdoEffectJournal($this->otherConnection(), self::PREFIX, $this->clock))->find_entry('k');
    self::assertSame(EffectState::Recorded, $entry?->state);
    self::assertEquals(self::utc('2026-10-01 12:00:05'), $entry->recorded_at);
    self::assertSame(['v' => 1], $this->journal->find('k')?->data, 'find() answers the result whatever the state');
  }

  public function test_mark_recorded_of_an_unknown_or_invalidated_key_is_a_no_op(): void {
    $this->journal->mark_recorded('never-performed');
    $this->journal->store('gone', new EffectResult());
    $this->journal->invalidate('gone', 'repair');
    $this->journal->mark_recorded('gone');

    self::assertNull($this->journal->find_entry('never-performed'));
    self::assertNull($this->journal->find_entry('gone'));
    self::assertSame(0, $this->countRows('ddd_effect_recorded'));
  }

  public function test_mark_recorded_commits_or_rolls_back_with_record(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $boundary = new PdoTransactionBoundary($this->db);

    try {
      $boundary->run(function (): void {
        $this->journal->mark_recorded('k');
        throw new \DomainException('record() failed after the mark');
      });
    } catch (\DomainException) {
    }
    self::assertSame(EffectState::Performed, $this->journal->find_entry('k')?->state, 'a rolled-back record() leaves the entry performed');

    $boundary->run(fn () => $this->journal->mark_recorded('k'));
    self::assertSame(EffectState::Recorded, $this->journal->find_entry('k')?->state);
  }

  public function test_storing_again_resets_the_entry_to_performed(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->journal->mark_recorded('k');
    $this->journal->invalidate('k', 'repair');

    $this->journal->store('k', new EffectResult(['v' => 2]));

    $entry = $this->journal->find_entry('k');
    self::assertSame(EffectState::Performed, $entry?->state);
    self::assertNull($entry->recorded_at);
    self::assertSame(['v' => 2], $entry->result->data);
  }

  public function test_store_inside_an_open_transaction_joins_it(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->journal->mark_recorded('k');

    try {
      $boundary->run(function (): void {
        $this->journal->store('k', new EffectResult(['v' => 2]));
        throw new \DomainException('rolled back');
      });
    } catch (\DomainException) {
    }

    self::assertSame(EffectState::Recorded, $this->journal->find_entry('k')?->state);
    self::assertSame(['v' => 1], $this->journal->find('k')?->data);
  }

  public function test_find_unrecorded_lists_old_performed_entries_oldest_first(): void {
    $this->journal->store('b', new EffectResult(['n' => 'b']));
    $this->clock->advance('PT10S');
    $this->journal->store('a', new EffectResult(['n' => 'a']));
    $this->clock->advance('PT10S');
    $this->journal->store('recorded', new EffectResult());
    $this->journal->mark_recorded('recorded');
    $this->journal->store('invalidated', new EffectResult());
    $this->journal->invalidate('invalidated', 'repair');
    $this->clock->advance('PT10S');
    $this->journal->store('young', new EffectResult());

    $due = $this->journal->find_unrecorded(self::utc('2026-10-01 12:00:25'), 10);

    self::assertSame(['b', 'a'], array_map(static fn (EffectEntry $e) => $e->key, $due));
    self::assertSame(EffectState::Performed, $due[0]->state);
    self::assertSame(['n' => 'b'], $due[0]->result->data);
    self::assertCount(1, $this->journal->find_unrecorded(self::utc('2026-10-01 12:00:25'), 1), 'the limit caps the list');
    self::assertSame([], $this->journal->find_unrecorded(self::utc('2026-10-01 12:00:25'), 0));
  }

  public function test_unrecorded_entries_are_the_operator_layer_effect(): void {
    $this->journal->store('stripe:charge:1', new EffectResult(['charge' => 'ch_1']));
    $this->journal->store('stripe:charge:2', new EffectResult(['charge' => 'ch_2']));
    $this->journal->mark_recorded('stripe:charge:2');
    $this->clock->advance('PT' . (UnrecordedEffects::DEFAULT_AFTER_SECONDS + 1) . 'S');

    $items = (new UnrecordedEffects($this->journal, 'tp', $this->clock))->items(Layer::Effect, 10);

    self::assertSame(['stripe:charge:1'], array_map(static fn ($i) => $i->key, $items));
    self::assertSame(['invalidate'], $items[0]->repairs);
  }
}
