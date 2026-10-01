<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Persistence\DbalEffectJournal;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\PostgresSchema;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/** D1: IEffectJournal on `ddd_effect_journal`, Postgres 16. */
final class DbalEffectJournalTest extends PostgresTestCase {

  private FrozenClock $clock;
  private DbalEffectJournal $journal;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->journal = new DbalEffectJournal($this->db, $this->clock);
  }

  public function test_it_is_the_core_port(): void {
    self::assertInstanceOf(IEffectJournal::class, $this->journal);
  }

  public function test_an_unknown_key_has_no_result(): void {
    self::assertNull($this->journal->find('stripe:customer:1'));
  }

  public function test_a_stored_result_is_found_with_its_data_and_external_ref(): void {
    $this->journal->store('stripe:customer:1', new EffectResult(
      ['customer' => 'cus_1', 'amount' => 1200, 'live' => false, 'note' => null, 'lines' => [['sku' => 'a', 'qty' => 2]]],
      'cus_1',
    ));

    $found = $this->journal->find('stripe:customer:1');
    self::assertNotNull($found);
    self::assertSame(['customer' => 'cus_1', 'amount' => 1200, 'live' => false, 'note' => null, 'lines' => [['sku' => 'a', 'qty' => 2]]], $found->data);
    self::assertSame('cus_1', $found->external_ref);
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
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_effect_journal'));
  }

  public function test_invalidate_makes_the_effect_perform_again_and_keeps_the_reason(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));

    $this->journal->invalidate('k', 'operator: customer deleted in Stripe');

    self::assertNull($this->journal->find('k'));
    $row = $this->db->fetchAssociative('SELECT invalidation_reason, invalidations, invalidated_at FROM ddd_effect_journal WHERE idempotency_key = ?', ['k']);
    self::assertSame('operator: customer deleted in Stripe', $row['invalidation_reason']);
    self::assertSame(1, (int) $row['invalidations']);
    self::assertNotNull($row['invalidated_at']);
  }

  public function test_invalidating_an_unknown_key_is_a_no_op(): void {
    $this->journal->invalidate('never-performed', 'why not');

    self::assertNull($this->journal->find('never-performed'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_effect_journal'));
  }

  public function test_a_store_after_invalidate_is_found_again(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->journal->invalidate('k', 'repair');

    $this->journal->store('k', new EffectResult(['v' => 2]));

    self::assertSame(['v' => 2], $this->journal->find('k')?->data);
  }

  public function test_invalidate_commits_or_rolls_back_with_the_repair_command(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $boundary = new DbalTransactionBoundary($this->db);

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

  public function test_a_storage_failure_is_a_runtime_exception(): void {
    $this->db->executeStatement('DROP TABLE ddd_effect_journal');

    $this->expectException(\RuntimeException::class);
    $this->journal->find('k');
  }

  // ── E2 (wave 5): entry states, ITracksEffectState ─────────────────────────

  public function test_it_tracks_entry_states(): void {
    self::assertInstanceOf(ITracksEffectState::class, $this->journal);
  }

  public function test_a_stored_entry_is_performed_and_not_recorded(): void {
    $this->journal->store('k', new EffectResult(['v' => 1], 'r1'));

    $entry = $this->journal->find_entry('k');
    self::assertNotNull($entry);
    self::assertSame('k', $entry->key);
    self::assertSame(EffectState::Performed, $entry->state);
    self::assertFalse($entry->is_recorded());
    self::assertSame(['v' => 1], $entry->result->data);
    self::assertSame('r1', $entry->result->external_ref);
    self::assertEquals(new \DateTimeImmutable('2026-10-01T12:00:00Z'), $entry->performed_at);
    self::assertNull($entry->recorded_at);
  }

  public function test_mark_recorded_sets_the_state_and_the_time(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $this->clock->advance('5 seconds');

    $this->journal->mark_recorded('k');

    $entry = $this->journal->find_entry('k');
    self::assertSame(EffectState::Recorded, $entry?->state);
    self::assertTrue($entry->is_recorded());
    self::assertEquals(new \DateTimeImmutable('2026-10-01T12:00:05Z'), $entry->recorded_at);
    self::assertSame(['v' => 1], $this->journal->find('k')?->data, 'find() answers the result whatever the state');
  }

  public function test_mark_recorded_of_an_unknown_key_is_a_no_op(): void {
    $this->journal->mark_recorded('never-performed');

    self::assertNull($this->journal->find_entry('never-performed'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_effect_journal'));
  }

  public function test_mark_recorded_commits_or_rolls_back_with_record(): void {
    $this->journal->store('k', new EffectResult(['v' => 1]));
    $boundary = new DbalTransactionBoundary($this->db);

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

  public function test_an_invalidated_entry_is_not_found_in_either_state(): void {
    $this->journal->store('performed', new EffectResult());
    $this->journal->store('recorded', new EffectResult());
    $this->journal->mark_recorded('recorded');

    $this->journal->invalidate('performed', 'repair');
    $this->journal->invalidate('recorded', 'repair');

    self::assertNull($this->journal->find_entry('performed'));
    self::assertNull($this->journal->find_entry('recorded'));
    $this->journal->mark_recorded('performed');
    self::assertNull($this->journal->find_entry('performed'), 'an invalidated row is not marked');
  }

  public function test_find_unrecorded_lists_old_performed_entries_oldest_first(): void {
    $this->journal->store('b', new EffectResult(['n' => 'b']));
    $this->clock->advance('10 seconds');
    $this->journal->store('a', new EffectResult(['n' => 'a']));
    $this->clock->advance('10 seconds');
    $this->journal->store('recorded', new EffectResult());
    $this->journal->mark_recorded('recorded');
    $this->journal->store('invalidated', new EffectResult());
    $this->journal->invalidate('invalidated', 'repair');
    $this->clock->advance('10 seconds');
    $this->journal->store('young', new EffectResult());

    $due = $this->journal->find_unrecorded(new \DateTimeImmutable('2026-10-01T12:00:25Z'), 10);

    self::assertSame(['b', 'a'], array_map(static fn (EffectEntry $e) => $e->key, $due));
    self::assertSame(EffectState::Performed, $due[0]->state);
    self::assertSame(['n' => 'b'], $due[0]->result->data);
    self::assertCount(1, $this->journal->find_unrecorded(new \DateTimeImmutable('2026-10-01T12:00:25Z'), 1), 'the limit caps the list');
    self::assertSame([], $this->journal->find_unrecorded(new \DateTimeImmutable('2026-10-01T12:00:25Z'), 0));
  }

  public function test_unrecorded_entries_are_the_operator_layer_effect(): void {
    $this->journal->store('stripe:charge:1', new EffectResult(['charge' => 'ch_1']));
    $this->journal->store('stripe:charge:2', new EffectResult(['charge' => 'ch_2']));
    $this->journal->mark_recorded('stripe:charge:2');
    $this->clock->advance((UnrecordedEffects::DEFAULT_AFTER_SECONDS + 1) . ' seconds');

    $items = (new UnrecordedEffects($this->journal, 'app', $this->clock))->items(Layer::Effect, 10);

    self::assertCount(1, $items);
    self::assertSame(Layer::Effect, $items[0]->layer);
    self::assertSame('stripe:charge:1', $items[0]->key);
    self::assertSame(['invalidate'], $items[0]->repairs);
  }

  public function test_the_table_prefix_is_honoured(): void {
    PostgresSchema::apply($this->db, 'p_');
    try {
      $journal = new DbalEffectJournal($this->db, $this->clock, 'p_');
      $journal->store('k', new EffectResult(['v' => 1]));

      self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM p_ddd_effect_journal'));
      self::assertNull($this->journal->find('k'));
    } finally {
      foreach (PostgresSchema::tables() as $t) {
        $this->db->executeStatement("DROP TABLE IF EXISTS p_$t CASCADE");
      }
    }
  }
}
