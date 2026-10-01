<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\FrozenClock;
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
