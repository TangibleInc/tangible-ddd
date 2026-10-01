<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\PdoEffectJournal;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\FrozenClock;

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
}
