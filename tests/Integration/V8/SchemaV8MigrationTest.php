<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Tests\Integration\V8\Fakes\V8IgnitedProcess;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ManualProcess;

use function TangibleDDD\WordPress\ddd_maybe_migrate;
use function TangibleDDD\WordPress\ddd_schema_installed;

/**
 * Schema v8 (register section 8 wave 3 wp bullet, R5): a fresh install, and
 * an upgrade of a v7 database that holds pending rows left by a 0.6 winner.
 */
final class SchemaV8MigrationTest extends V8TestCase {

  private const E1 = '9b2c7a3e-5d1f-4c6a-8e2b-1f0a3c5d7e91';
  private const E2 = '0c1d2e3f-4a5b-4c6d-8e7f-8091a2b3c4d5';

  private const V8_TABLES = ['ddd_wakeups', 'ddd_delivery_ledger', 'ddd_relay_pauses'];

  public function test_a_fresh_install_creates_every_v8_table_and_column(): void {
    ddd_maybe_migrate($this->config);

    self::assertSame(8, ddd_schema_installed($this->config));
    foreach (self::V8_TABLES as $t) {
      self::assertTrue($this->tableExists($this->table($t)), "$t created");
    }
    self::assertTrue($this->columnExists($this->table('integration_outbox'), 'claim_token'));
    foreach (['version', 'ignition_key', 'quarantine_reason', 'start_path'] as $c) {
      self::assertTrue($this->columnExists($this->table('long_processes'), $c), "long_processes.$c");
    }
    self::assertSame(['process_class', 'ignition_key'], $this->uniqueIgnitionColumns());

    $report = get_option($this->config->option('ddd_v8_migration_report'));
    self::assertSame(0, $report['ignition_backfilled']);
    self::assertSame([], $report['ignition_duplicates']);
    self::assertSame(0, $report['wakeups_backfilled']);
  }

  public function test_an_upgrade_from_v7_keeps_every_pending_row_and_backfills(): void {
    SchemaV7::install($this->config);
    SchemaV7::outbox($this->config, 'aaaaaaaa-0000-4000-8000-000000000001');
    SchemaV7::outbox($this->config, 'aaaaaaaa-0000-4000-8000-000000000002', 'completed');
    $first = SchemaV7::process($this->config, V8IgnitedProcess::class, 'suspended', 1, self::E1);
    $dup = SchemaV7::process($this->config, V8IgnitedProcess::class, 'completed', 1, self::E1);
    $manual = SchemaV7::process($this->config, V8ManualProcess::class, 'completed', 1, self::E2);
    $gone = SchemaV7::process($this->config, 'Gone\\Process\\ClassName', 'failed', 0, self::E2);
    $scheduled = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 2, null, 'cli');
    update_option($this->config->option('outbox_pauses'), ['ops' => ['selector' => 'v8.fact', 'until' => -1]], false);

    $timeoutAt = time() + 7200;
    $timeoutId = as_schedule_single_action($timeoutAt, $this->config->hook('await_timeout'), ['process_id' => $first, 'step_index' => 1], $this->config->as_group('processes'));
    $continueId = as_enqueue_async_action($this->config->hook('process_continue'), ['process_id' => $scheduled], $this->config->as_group('processes'));

    ddd_maybe_migrate($this->config);

    self::assertSame(8, ddd_schema_installed($this->config));
    foreach (self::V8_TABLES as $t) {
      self::assertTrue($this->tableExists($this->table($t)), "$t created on upgrade");
    }

    // Nothing deleted, nothing re-statused.
    self::assertSame(
      [['status' => 'pending', 'claim_token' => null], ['status' => 'completed', 'claim_token' => null]],
      $this->rows("SELECT status, claim_token FROM `{$this->table('integration_outbox')}` ORDER BY id")
    );
    $processes = $this->rows("SELECT id, status, version, ignition_key, quarantine_reason, start_path FROM `{$this->table('long_processes')}` ORDER BY id");
    self::assertCount(5, $processes);
    self::assertSame([null], array_values(array_unique(array_column($processes, 'start_path'))), '0.6 rows keep start_path NULL');
    self::assertSame(['1'], array_values(array_unique(array_column($processes, 'version'))), 'every row starts at version 1');

    $keys = array_column($processes, 'ignition_key', 'id');
    self::assertSame(IgnitionKey::for(self::E1, V8IgnitedProcess::class), $keys[$first], 'the first ignition-path row keeps the key');
    self::assertNull($keys[$dup], 'a duplicate ignition is left without a key, not deleted');
    self::assertNull($keys[$manual], 'a class without #[StartsOn] is not on the ignition path');
    self::assertNull($keys[$gone], 'an unknown class is skipped');
    self::assertNull($keys[$scheduled]);

    $report = get_option($this->config->option('ddd_v8_migration_report'));
    self::assertSame(1, $report['ignition_backfilled']);
    self::assertSame(2, $report['ignition_skipped']);
    self::assertSame([['process_class' => V8IgnitedProcess::class, 'event_id' => self::E1, 'kept' => $first, 'duplicate' => $dup]], $report['ignition_duplicates']);

    // Pending AS actions became intent rows; the actions themselves are untouched.
    self::assertSame(2, $report['wakeups_backfilled']);
    $intents = $this->rows("SELECT idempotency_key, kind, process_id, step_index, expected_status, status, as_action_id, UNIX_TIMESTAMP(due_at) AS due FROM `{$this->table('ddd_wakeups')}` ORDER BY idempotency_key");
    self::assertSame([
      ['idempotency_key' => "continue:$scheduled:2", 'kind' => 'continue', 'process_id' => (string) $scheduled, 'step_index' => '2', 'expected_status' => 'scheduled', 'status' => 'pending', 'as_action_id' => (string) $continueId],
      ['idempotency_key' => "timeout:$first:1", 'kind' => 'timeout', 'process_id' => (string) $first, 'step_index' => '1', 'expected_status' => 'suspended', 'status' => 'pending', 'as_action_id' => (string) $timeoutId],
    ], array_map(static fn ($r) => array_diff_key($r, ['due' => 1]), $intents));
    self::assertSame($timeoutAt, (int) $intents[1]['due'], 'the intent keeps the action\'s absolute due time');
    self::assertCount(1, $this->pendingActions($this->config->hook('await_timeout')));
    self::assertCount(1, $this->pendingActions($this->config->hook('process_continue')));

    // The 0.6 pause option is kept (still read until drained).
    self::assertSame(['ops' => ['selector' => 'v8.fact', 'until' => -1]], get_option($this->config->option('outbox_pauses')));

    // The upgraded tables serve the v8 adapters: the 0.6 pending row is
    // held by the 0.6 pause, then claimed with a token once it is released.
    $store = new \TangibleDDD\WordPress\Adapter\WpdbOutboxStore(
      new \TangibleDDD\Infra\Persistence\OutboxRepository($this->config, new \TangibleDDD\Application\Outbox\OutboxConfig()),
      $this->config
    );
    self::assertSame([], $store->claim(10, new \DateTimeImmutable('+1 second'), 60));
    delete_option($this->config->option('outbox_pauses'));
    [$claim] = $store->claim(10, new \DateTimeImmutable('+1 second'), 60);
    self::assertSame('aaaaaaaa-0000-4000-8000-000000000001', $claim->event_id);
    self::assertTrue($store->accept($claim, '1'));
  }

  public function test_the_v8_migration_is_idempotent(): void {
    SchemaV7::install($this->config);
    $first = SchemaV7::process($this->config, V8IgnitedProcess::class, 'suspended', 1, self::E1);
    as_schedule_single_action(time() + 60, $this->config->hook('await_timeout'), ['process_id' => $first, 'step_index' => 1]);

    ddd_maybe_migrate($this->config);
    delete_option(\TangibleDDD\WordPress\ddd_schema_version_key($this->config));
    wp_cache_flush();
    ddd_maybe_migrate($this->config);

    self::assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('ddd_wakeups')}`"));
    self::assertSame(IgnitionKey::for(self::E1, V8IgnitedProcess::class), $this->wpdb->get_var("SELECT ignition_key FROM `{$this->table('long_processes')}`"));
    $report = get_option($this->config->option('ddd_v8_migration_report'));
    self::assertSame([0, [], 0], [$report['ignition_backfilled'], $report['ignition_duplicates'], $report['wakeups_backfilled']]);
  }

  public function test_a_0_6_winner_still_writes_after_the_upgrade(): void {
    // Rollback safety (R5, B18): the 0.6 repository's INSERT names none of
    // the v8 columns, and every one of them is nullable or defaulted.
    ddd_maybe_migrate($this->config);
    $p = new V8ManualProcess(3);
    $p->initialize_lifecycle('33333333-3333-4333-8333-333333333333', \TangibleDDD\Application\Process\ProcessSteps::from_reflection([new \ReflectionMethod($p, 'react')], []));
    $id = (new ProcessRepository($this->config))->save($p);

    self::assertGreaterThan(0, $id);
    self::assertSame(
      ['version' => '1', 'ignition_key' => null, 'quarantine_reason' => null],
      $this->rows("SELECT version, ignition_key, quarantine_reason FROM `{$this->table('long_processes')}` WHERE id = $id")[0]
    );
  }

  /** @return list<string> */
  private function uniqueIgnitionColumns(): array {
    return array_column($this->rows($this->wpdb->prepare(
      'SELECT COLUMN_NAME AS c, NON_UNIQUE AS n FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX',
      $this->table('long_processes'),
      'uniq_ignition_key'
    )), 'c');
  }
}
