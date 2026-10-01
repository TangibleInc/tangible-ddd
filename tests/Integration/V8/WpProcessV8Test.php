<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Tests\Integration\V8\Fakes\V8IgnitedProcess;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ManualProcess;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpRepositoryProcessStore;

/**
 * The final wp process store and lock on schema v8 (register 3.7, 3.8, X7,
 * ruling on #76): ignition gated by UNIQUE (process_class, ignition_key)
 * AND the ignited_by_event_id check inside the ignition lock, version
 * fencing, quarantine, the stranded scan, and GET_LOCK on the new and the
 * legacy name.
 */
final class WpProcessV8Test extends V8TestCase {

  private const E1 = '9b2c7a3e-5d1f-4c6a-8e2b-1f0a3c5d7e91';

  private FrozenClock $clock;

  private WpdbProcessStore $store;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    $this->store = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $this->clock);
    V8IgnitedProcess::$runs = 0;
  }

  private function process(LongProcess $p, string $status = 'pending'): LongProcess {
    $p->initialize_lifecycle('33333333-3333-4333-8333-333333333333', ProcessSteps::from_reflection([new \ReflectionMethod($p, 'react')], []));
    if ($status !== 'pending') {
      $p->advance(status: $status, payload: null);
    }
    return $p;
  }

  /** @return array<string, mixed> */
  private function row(int $id): array {
    return $this->rows("SELECT * FROM `{$this->table('long_processes')}` WHERE id = $id")[0];
  }

  public function test_the_factory_serves_the_v8_store_only_for_a_migrated_framework_repository(): void {
    self::assertInstanceOf(WpdbProcessStore::class, HostDefaults::for(IProcessStore::class, $this->config, new ProcessRepository($this->config)));

    update_option($this->config->option('ddd_schema_version'), 7, false);
    self::assertInstanceOf(WpRepositoryProcessStore::class, HostDefaults::for(IProcessStore::class, $this->config, new ProcessRepository($this->config)));
  }

  public function test_insert_ignited_writes_the_ignition_key_and_dedups_a_redelivery(): void {
    $p = $this->process(new V8IgnitedProcess(1));
    $p->mark_ignited_by(self::E1);

    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($p, V8IgnitedProcess::class, self::E1));
    $again = $this->process(new V8IgnitedProcess(1));
    $again->mark_ignited_by(self::E1);
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($again, V8IgnitedProcess::class, self::E1));

    self::assertNull($again->get_id(), 'the loser is not persisted');
    $row = $this->row((int) $p->get_id());
    self::assertSame([IgnitionKey::for(self::E1, V8IgnitedProcess::class), '1', self::E1], [$row['ignition_key'], $row['version'], $row['ignited_by_event_id']]);
    self::assertSame('1', (string) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('long_processes')}`"));
  }

  public function test_insert_ignited_sees_an_ignition_a_0_6_winner_wrote(): void {
    // Upgrade -> rollback -> a 0.6 ignition (ignited_by_event_id, no key) -> roll-forward.
    SchemaV7::process($this->config, V8IgnitedProcess::class, 'completed', 1, self::E1);

    $p = $this->process(new V8IgnitedProcess(1));
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($p, V8IgnitedProcess::class, self::E1));
    self::assertSame('1', (string) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('long_processes')}`"));
  }

  public function test_the_unique_key_alone_rejects_a_second_ignition(): void {
    // A row keyed by another writer whose ignited_by_event_id differs: only
    // the UNIQUE (process_class, ignition_key) gate can see it.
    $id = SchemaV7::process($this->config, V8IgnitedProcess::class, 'running', 0, 'not-the-same');
    $this->wpdb->update($this->table('long_processes'), ['ignition_key' => IgnitionKey::for(self::E1, V8IgnitedProcess::class)], ['id' => $id]);

    $p = $this->process(new V8IgnitedProcess(1));
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($p, V8IgnitedProcess::class, self::E1));
  }

  public function test_manual_starts_are_never_deduped(): void {
    foreach ([1, 2] as $n) {
      $p = $this->process(new V8IgnitedProcess($n));
      $p->mark_ignited_by(self::E1);
      $this->store->insert($p);
    }

    $manual = ['ignited_by_event_id' => self::E1, 'ignition_key' => null, 'version' => '1', 'start_path' => 'manual'];
    self::assertSame(
      [$manual, $manual],
      $this->rows("SELECT ignited_by_event_id, ignition_key, version, start_path FROM `{$this->table('long_processes')}` ORDER BY id")
    );

    // process.manual-start-in-drain: a later #[StartsOn] ignition of that
    // class by the same fact still ignites, once.
    $later = $this->process(new V8IgnitedProcess(3));
    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($later, V8IgnitedProcess::class, self::E1));
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($this->process(new V8IgnitedProcess(3)), V8IgnitedProcess::class, self::E1));
    self::assertSame('ignition', $this->row((int) $later->get_id())['start_path']);
  }

  public function test_save_and_touch_are_version_fenced(): void {
    $p = $this->process(new V8ManualProcess(1));
    $id = $this->store->insert($p);
    self::assertSame(1, $this->store->versionOf($id));

    $p->advance(status: 'running', payload: null);
    self::assertSame(2, $this->store->save($p, 1));
    self::assertSame(3, $this->store->touch($id, 2));
    self::assertSame(3, $this->store->versionOf($id));
    self::assertSame('running', $this->row($id)['status']);

    try {
      $this->store->save($p, 2);
      self::fail('a stale version must not overwrite');
    } catch (ConcurrentProcessModification) {
    }
    $this->expectException(ConcurrentProcessModification::class);
    $this->store->touch($id, 1);
  }

  public function test_an_undecodable_row_is_quarantined_with_status_failed(): void {
    $id = SchemaV7::process($this->config, 'Gone\\Process\\ClassName', 'suspended', 1, null);

    try {
      $this->store->find($id);
      self::fail('expected QuarantinedProcess');
    } catch (QuarantinedProcess) {
    }

    $row = $this->row($id);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('Gone\\Process\\ClassName', (string) $row['quarantine_reason']);
    self::assertNull($this->store->find(999999));

    try {
      $this->store->find($id);
      self::fail('still quarantined');
    } catch (QuarantinedProcess) {
    }
    self::assertSame($row['version'], $this->row($id)['version'], 'a second find does not write again');
  }

  public function test_find_waiting_for_returns_suspended_ids(): void {
    $a = SchemaV7::process($this->config, V8ManualProcess::class, 'suspended', 1, null);
    $b = SchemaV7::process($this->config, V8ManualProcess::class, 'completed', 1, null);
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET waiting_for = 'X\\\\Fact' WHERE id IN ($a, $b)");

    self::assertSame([$a], $this->store->findWaitingFor('X\\Fact'));
  }

  public function test_the_stranded_scan_reports_old_rows_with_no_live_intent(): void {
    $old = $this->clock->now()->modify('-16 minutes')->format('Y-m-d H:i:s');
    $scheduled = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 2, null);
    $running = SchemaV7::process($this->config, V8ManualProcess::class, 'running', 1, null);
    $covered = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 1, null);
    $fresh = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 1, null);
    $done = SchemaV7::process($this->config, V8ManualProcess::class, 'completed', 1, null);
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET updated_at = '$old' WHERE id IN ($scheduled, $running, $covered, $done)");
    $this->wpdb->insert($this->table('ddd_wakeups'), [
      'idempotency_key' => "continue:$covered:1", 'kind' => 'continue', 'process_id' => $covered, 'step_index' => 1,
      'expected_status' => 'scheduled', 'due_at' => $old, 'status' => 'pending', 'created_at' => $old, 'updated_at' => $old,
    ]);

    $stranded = $this->store->findStranded($this->clock->now());

    self::assertSame([[$scheduled, 'scheduled', 2], [$running, 'running', 1]], array_map(static fn ($s) => [$s->processId, $s->status, $s->stepIndex], $stranded));
    self::assertSame(V8ManualProcess::class, $stranded[0]->processClass);
    unset($fresh);
  }

  public function test_the_lock_takes_the_new_and_the_legacy_name_and_releases_both(): void {
    $lock = new GetLockProcessLock();
    $key = new LockKey($this->config->prefix(), '', 41);

    $h = $lock->acquire($key, 1.0);
    $other = $this->secondConnection();
    self::assertSame('0', (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', $key->mysqlName())), 'new name held');
    self::assertSame('0', (string) $other->get_var("SELECT IS_FREE_LOCK('ddd_process_41')"), 'legacy name held');

    $lock->release($h);
    self::assertSame('1', (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', $key->mysqlName())));
    self::assertSame('1', (string) $other->get_var("SELECT IS_FREE_LOCK('ddd_process_41')"));
    self::assertSame(0, $lock->heldCount());
  }

  public function test_a_0_6_holder_of_the_legacy_name_excludes_the_new_lock_and_nothing_stays_held(): void {
    $other = $this->secondConnection();
    self::assertSame('1', (string) $other->get_var("SELECT GET_LOCK('ddd_process_42', 0)"));
    $lock = new GetLockProcessLock();
    $key = new LockKey($this->config->prefix(), '', 42);

    try {
      $lock->acquire($key, 0.0);
      self::fail('the legacy holder must exclude N');
    } catch (LockNotAcquired $e) {
      self::assertStringContainsString('timed out', $e->getMessage());
    }
    self::assertSame(0, $lock->heldCount());
    self::assertSame('1', (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', $key->mysqlName())), 'the new name was released again');
    $other->query("SELECT RELEASE_LOCK('ddd_process_42')");
  }

  public function test_a_holder_of_the_new_name_excludes_too(): void {
    $key = new LockKey($this->config->prefix(), '', 43);
    $other = $this->secondConnection();
    self::assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $key->mysqlName())));

    try {
      (new GetLockProcessLock())->acquire($key, 0.0);
      self::fail('the new-name holder must exclude');
    } catch (LockNotAcquired) {
    }
    self::assertSame('1', (string) $other->get_var("SELECT IS_FREE_LOCK('ddd_process_43')"), 'the legacy name was never left held');
    $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $key->mysqlName()));
  }

  private function secondConnection(): \wpdb {
    $db = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $db->suppress_errors(true);
    return $db;
  }
}
