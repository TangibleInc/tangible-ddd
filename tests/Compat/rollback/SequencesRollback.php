<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbGatherSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbHopSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbJournal;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderPlaced;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderSaga;
use TangibleDDD\Tests\Compat\Rollback\Support\RollbackTestCase;

/**
 * Register 7.3, the three sequence fixtures.
 *
 * (1) upgrade → stranded scan → rollback → drain: the scan mints no second
 *     `{prefix}_process_continue` for a row whose 0.6 action is queued
 *     (the legacy `['process_id']` arg cannot carry a step index, so a
 *     duplicate would re-run a step after the rollback), and no step
 *     re-runs.
 * (2) upgrade → rollback → a 0.6 ignition → roll-forward → redelivery of
 *     the same fact: no second process (insertIgnited's
 *     ignited_by_event_id check for rows a 0.6 copy wrote).
 * (3) upgrade with 0.6-queued continuations and timeouts: the v8 migration
 *     backfills their intent rows, and N neither re-projects them nor
 *     loses them.
 */
#[Group('compat')]
#[Group('rollback')]
final class SequencesRollback extends RollbackTestCase {

  #[DataProvider('legacyVersions')]
  public function test_sequence_1_upgrade_stranded_scan_rollback_drain_runs_no_step_twice(string $version): void {
    $legacy = $this->legacy($version);
    $legacy->run('migrate');
    $this->legacyIdBase();
    $hop = $legacy->run('start', ['class' => RbHopSaga::class, 'params' => ['s1']])['id'];
    self::assertSame('scheduled', $this->processRow($hop)['status']);
    self::assertCount(1, $this->pending('process_continue'));
    $this->age(20 * 60); // the deploy took a while: the row is past the stranded threshold

    // Upgrade. Even with the backfilled intent gone (as if it were never
    // written), the stranded scan finds the queued 0.6 action and mints nothing.
    $this->nUpgrade();
    $this->nBoot();
    $this->wpdb->query("DELETE FROM `{$this->table('ddd_wakeups')}` WHERE process_id = $hop");
    $tick = $this->nTick();
    self::assertSame([], $tick->errors);
    self::assertCount(1, $this->pending('process_continue'), 'no duplicate process_continue was minted');
    self::assertSame([], $this->rows("SELECT id FROM `{$this->table('ddd_wakeups')}` WHERE process_id = $hop"), 'no intent minted beside the 0.6 action');

    // Rollback, then the legacy winner drains.
    $legacy->run('migrate');
    $due = $legacy->run('run_due');
    self::assertSame([], $due['failed'], json_encode($due));
    self::assertSame(1, RbJournal::count('hop:first:s1'), 'no step re-ran');
    self::assertLessThanOrEqual(1, count($this->pending('process_continue')), 'never two continuations for one row');
  }

  #[DataProvider('legacyVersions')]
  public function test_sequence_2_a_0_6_ignition_between_rollback_and_roll_forward_is_not_repeated(string $version): void {
    $legacy = $this->legacy($version);
    $eventId = 'e3000000-0000-4000-8000-000000000002';

    // Upgrade.
    $this->nInstall();
    $this->nBoot();

    // Rollback: the 0.6 winner ignites from the fact (no ignition_key, no start_path).
    $legacy->run('migrate');
    $legacy->run('deliver', ['class' => RbOrderPlaced::class, 'params' => ['s2'], 'event_id' => $eventId]);
    $rows = $this->rows("SELECT id, ignition_key, start_path FROM `{$this->table('long_processes')}`");
    self::assertCount(1, $rows);
    self::assertSame([null, null], [$rows[0]['ignition_key'], $rows[0]['start_path']]);

    // Roll forward, and the same fact is redelivered to N.
    $this->nUpgrade();
    do_action(RbOrderPlaced::integration_action(), IntegrationEnvelope::wrap((new RbOrderPlaced('s2'))->integration_payload(), Uuid::v4(), 1, $eventId));

    self::assertCount(1, $this->rows("SELECT id FROM `{$this->table('long_processes')}` WHERE process_class = '" . esc_sql(RbOrderSaga::class) . "'"), 'no second process');
    self::assertSame(1, RbJournal::count('order:open:s2'));
  }

  #[DataProvider('legacyVersions')]
  public function test_sequence_3_the_v8_migration_backfills_intents_for_0_6_queued_wakes(string $version): void {
    $legacy = $this->legacy($version);
    $legacy->run('migrate');
    $this->legacyIdBase();
    $gather = $legacy->run('start', ['class' => RbGatherSaga::class, 'params' => ['s3']])['id'];
    $hop = $legacy->run('start', ['class' => RbHopSaga::class, 'params' => ['s3']])['id'];
    $actions = [count($this->pending('await_timeout')), count($this->pending('process_continue'))];
    self::assertSame([1, 1], $actions);

    $this->nUpgrade();
    $this->nBoot();

    $intents = $this->rows("SELECT idempotency_key, kind, process_id, status FROM `{$this->table('ddd_wakeups')}` ORDER BY process_id, kind");
    self::assertEqualsCanonicalizing([
      ['idempotency_key' => "timeout:$gather:0", 'kind' => 'timeout', 'process_id' => (string) $gather, 'status' => 'pending'],
      ['idempotency_key' => "continue:$hop:1", 'kind' => 'continue', 'process_id' => (string) $hop, 'status' => 'pending'],
    ], $intents);

    // N's tick neither re-projects nor duplicates the queued 0.6 actions.
    self::assertSame([], $this->nTick()->errors);
    self::assertSame($actions, [count($this->pending('await_timeout')), count($this->pending('process_continue'))]);

    // N runs the 0.6 continuation and closes its intent; the alarm fires at its instant and closes too.
    $this->nDrain();
    self::assertSame(1, RbJournal::count('hop:second:s3'));
    self::assertSame('done', $this->wpdb->get_var("SELECT status FROM `{$this->table('ddd_wakeups')}` WHERE idempotency_key = 'continue:$hop:1'"));
    $this->age(RbGatherSaga::TIMEOUT_SECONDS + 1);
    self::assertSame([], $this->nDrain()['failed']);
    self::assertSame(1, RbJournal::count('gather:assemble:s3:0'));
    self::assertSame('done', $this->wpdb->get_var("SELECT status FROM `{$this->table('ddd_wakeups')}` WHERE idempotency_key = 'timeout:$gather:0'"));
  }
}
