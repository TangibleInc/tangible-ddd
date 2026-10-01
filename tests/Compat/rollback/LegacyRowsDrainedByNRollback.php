<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbGatherSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbHopSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbJournal;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbNote;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderPlaced;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbPartShipped;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbPaymentReceived;
use TangibleDDD\Tests\Compat\Rollback\Support\RollbackTestCase;

/**
 * Register 7.3, first half: pending rows WRITTEN BY A LEGACY WINNER, then
 * upgraded to and DRAINED BY N.
 *
 * The legacy copy installs its own schema (v6 for 0.6.2, v7 for 0.6.3+)
 * and writes, through its own classes: outbox rows pending, delayed
 * (delay_seconds > 0), is_unique, leased by a 0.6 fetch, and dead-lettered
 * (DLQ row); a pause hold in the 0.6 option; a fact relayed to a pending
 * `{prefix}_integration_*` action with its wrapped envelope; the recurring
 * `{prefix}_outbox_process`; processes suspended on AwaitEvent and on
 * AwaitAll (with its `{prefix}_await_timeout` action, legacy associative
 * args), a `scheduled` one with its `{prefix}_process_continue`, a
 * pre-existing duplicate ignition, a behaviour workflow with a meta row and
 * a work item, and a command audit pair. Rows a 0.6 copy only leaves at
 * rest by dying (`running`, `compensating`) are its own rows with the
 * status a crash leaves.
 *
 * N then upgrades (its v8 migration over that schema) and drains.
 */
#[Group('compat')]
#[Group('rollback')]
final class LegacyRowsDrainedByNRollback extends RollbackTestCase {

  #[DataProvider('legacyVersions')]
  public function test_rows_a_legacy_winner_wrote_are_upgraded_and_drained_by_n(string $version): void {
    $legacy = $this->legacy($version);

    // ── the legacy winner's site ────────────────────────────────────────────
    $installed = $legacy->run('migrate');
    $this->legacyIdBase();
    self::assertSame($installed['legacy_schema'], $installed['installed'], "L-$version installed its own schema");

    // A fact the 0.6 relay already handed to Action Scheduler (a pending
    // integration action with its wrapped envelope).
    [$relayed] = $legacy->run('publish', ['facts' => [[RbOrderPlaced::class, ['o-relayed']]]])['ids'];
    self::assertSame(1, $legacy->run('relay')['completed']);
    self::assertCount(1, $this->pending('integration_rb_order_placed'));
    // A row a 0.6 fetch leased (locked_until = now + 300 s), then the rest.
    [$leased] = $legacy->run('publish', ['facts' => [[RbNote::class, ['leased']]]])['ids'];
    self::assertSame([$leased], $legacy->run('lease')['leased']);
    [$pending, $delayed, $unique, $dead] = $legacy->run('publish', ['facts' => [
      [RbNote::class, ['pending']],
      [RbNote::class, ['delayed', 120]],
      [RbNote::class, ['unique', 0, true]],
      [RbNote::class, ['dead']],
    ]])['ids'];
    $legacy->run('fail', ['event_id' => $dead, 'times' => 5, 'dlq' => true]);
    $legacy->run('pause', ['holder' => 'maintenance', 'selector' => 'rb_held', 'until' => -1]);
    $legacy->run('schedule_recurring');

    $order = $legacy->run('start', ['class' => RbOrderSaga::class, 'params' => ['o-await']])['id'];
    $gather = $legacy->run('start', ['class' => RbGatherSaga::class, 'params' => ['o-gather']])['id'];
    $hop = $legacy->run('start', ['class' => RbHopSaga::class, 'params' => ['o-hop']])['id'];
    $running = $legacy->run('start', ['class' => RbOrderSaga::class, 'params' => ['o-running']])['id'];
    $compensating = $legacy->run('start', ['class' => RbOrderSaga::class, 'params' => ['o-compensating']])['id'];
    $legacy->run('deliver', ['class' => RbOrderPlaced::class, 'params' => ['o-ignited'], 'event_id' => 'e1000000-0000-4000-8000-000000000001']);
    $duplicate = $legacy->run('save_duplicate', ['class' => RbOrderSaga::class, 'params' => ['o-ignited'], 'event_id' => 'e1000000-0000-4000-8000-000000000001'])['id'];
    // What a 0.6 worker that died mid-step / mid-compensation leaves at rest.
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET status = 'running', updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) WHERE id = $running");
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET status = 'compensating' WHERE id = $compensating");
    $workflow = $legacy->run('workflow')['id'];
    $audit = $legacy->run('audit')['command_id'];

    self::assertSame(['order:open:o-await', 'gather:request:o-gather', 'hop:first:o-hop', 'order:open:o-running', 'order:open:o-compensating', 'order:open:o-ignited'], array_column(RbJournal::all(), 'label'));
    self::assertSame('suspended', $this->processRow($order)['status']);
    self::assertSame('suspended', $this->processRow($gather)['status']);
    self::assertSame('scheduled', $this->processRow($hop)['status']);
    self::assertCount(1, $this->pending('await_timeout'));
    self::assertCount(1, $this->pending('process_continue'));
    self::assertCount(1, $this->pending('outbox_process'));
    $untouched = $this->checksum([$this->table('behaviour_workflows'), $this->table('behaviour_workflow_items'), $this->table('command_audit')]);

    // ── upgrade: N becomes the winner ───────────────────────────────────────
    $this->nUpgrade();
    $this->nBoot();

    // Sequence 3: the v8 migration backfilled intent rows for the 0.6-queued wakes.
    $intents = $this->rows("SELECT kind, process_id, step_index, status FROM `{$this->table('ddd_wakeups')}` ORDER BY id");
    self::assertEqualsCanonicalizing([
      ['kind' => 'timeout', 'process_id' => (string) $gather, 'step_index' => '0', 'status' => 'pending'],
      ['kind' => 'continue', 'process_id' => (string) $hop, 'step_index' => '1', 'status' => 'pending'],
    ], $intents);
    // Ignition keys: only the first ignition of the fact is keyed; the duplicate is reported, never deleted.
    $keys = $this->rows("SELECT id, ignition_key FROM `{$this->table('long_processes')}` WHERE ignited_by_event_id = 'e1000000-0000-4000-8000-000000000001' ORDER BY id");
    self::assertCount(2, $keys);
    self::assertNotNull($keys[0]['ignition_key']);
    self::assertNull($keys[1]['ignition_key'], 'the pre-existing duplicate is kept and left unkeyed');
    self::assertSame((string) $duplicate, $keys[1]['id']);

    // Every legacy-serialized process decodes under N.
    foreach ([$order, $gather, $hop, $running, $compensating, $duplicate] as $id) {
      self::assertNotNull($this->processStore->find($id), "process #$id decodes under N");
      self::assertNull($this->processRow($id)['quarantine_reason']);
    }

    // ── N drains ────────────────────────────────────────────────────────────
    $drain = $this->nDrain();
    self::assertSame([], $drain['failed']);
    self::assertSame('completed', $this->outboxRow($pending)['status'], 'N relays a pending 0.6 row');
    self::assertSame('completed', $this->outboxRow($unique)['status']);
    self::assertSame('pending', $this->outboxRow($leased)['status'], 'a 0.6 lease is honoured');
    self::assertSame('pending', $this->outboxRow($delayed)['status'], 'not due yet');
    self::assertSame('dlq', $this->outboxRow($dead)['status']);
    self::assertSame(1, RbJournal::count('note:pending'));
    self::assertSame(1, RbJournal::count('order:open:o-relayed'), 'the 0.6-queued integration action ignited under N');
    self::assertSame(1, RbJournal::count('hop:second:o-hop'), 'the 0.6 continuation ran under N (0.6 itself re-schedules an #[Async] step forever)');
    self::assertSame('completed', $this->processRow($hop)['status']);

    // The legacy pause option still holds its selector; the DLQ row is listed.
    self::assertContains('rb_held', array_values(array_map(static fn ($h) => $h['selector'], (array) get_option($this->config->option('outbox_pauses'), []))));
    self::assertSame([$dead], array_map(static fn ($l) => $l->event_id, $this->admin()->dead_letters(10)));

    // Later: the 0.6 lease expired, the delay is due (once: no second delay).
    $this->age(400);
    $this->nDrain();
    self::assertSame('completed', $this->outboxRow($leased)['status']);
    self::assertSame('completed', $this->outboxRow($delayed)['status']);
    self::assertSame(1, RbJournal::count('note:delayed'));
    self::assertSame(1, RbJournal::count('note:leased'));

    // Awaited facts resume the 0.6-suspended processes under N.
    [$pay, $partA, $partB] = $this->nPublish(new RbPaymentReceived('o-await'), new RbPartShipped('o-gather', 'a'), new RbPartShipped('o-gather', 'b'));
    $this->nDrain();
    self::assertSame('completed', $this->processRow($order)['status']);
    self::assertSame(1, RbJournal::count('order:settle:o-await'));
    self::assertSame('completed', $this->processRow($gather)['status']);
    self::assertSame(1, RbJournal::count('gather:assemble:o-gather:2'), json_encode(RbJournal::all()));

    // The backfilled alarm of the satisfied AwaitAll is stale under N: it fires as a no-op.
    $this->age(RbGatherSaga::TIMEOUT_SECONDS + 1);
    self::assertSame([], $this->nDrain()['failed']);
    self::assertSame(0, RbJournal::count('gather:assemble:o-gather:0'));

    // The 0.6 `running` row is stranded (operator view with repairs), never re-run on its own.
    self::assertSame(0, RbJournal::count('order:settle:o-running'));
    $stranded = array_values(array_filter(
      (new \TangibleDDD\WordPress\Adapter\WpOperatorView($this->config))->list('process'),
      static fn (array $i) => str_starts_with($i['key'], "#$running "),
    ));
    self::assertCount(1, $stranded);
    self::assertSame(['resume-stranded', 'fail-stranded'], $stranded[0]['repair_actions']);

    // Workflows, work items, meta and audit rows are untouched by the upgrade and the drain.
    self::assertSame($untouched, $this->checksum([$this->table('behaviour_workflows'), $this->table('behaviour_workflow_items'), $this->table('command_audit')]));
    self::assertGreaterThan(0, $workflow);
    self::assertNotSame('', $audit);
  }

  /** @var array<string, list<string>> the columns each table had under the legacy schema */
  private array $legacyColumns = [];

  /**
   * A checksum of $tables over the columns the LEGACY schema created (an
   * additive N schema may add columns; R5 says it changes no existing one).
   *
   * @param list<string> $tables
   */
  private function checksum(array $tables): string {
    $parts = [];
    foreach ($tables as $t) {
      $this->legacyColumns[$t] ??= array_map('strval', (array) $this->wpdb->get_col("SHOW COLUMNS FROM `$t`"));
      $columns = implode(', ', array_map(static fn (string $c) => "`$c`", $this->legacyColumns[$t]));
      $parts[] = $t . ':' . json_encode($this->rows("SELECT $columns FROM `$t` ORDER BY 1"));
    }
    return hash('sha256', implode('|', $parts));
  }
}
