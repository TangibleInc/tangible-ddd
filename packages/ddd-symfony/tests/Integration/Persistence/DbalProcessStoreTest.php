<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Process\IMatchesFactAncestry;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\LargeStringProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\AppDestroyed;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\ChildGone;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\JobDone;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\MarkerAwait;
use TangibleDDD\Symfony\Tests\Support\Fixtures\OrderPayload;
use TangibleDDD\Symfony\Tests\Support\Fixtures\OrderProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingMarker;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * IProcessStore on Postgres 16 (register 3.8, X7, 5.2, 5.3; CR-5).
 * Each test owns fresh tables; nothing is wrapped in a per-test transaction.
 */
final class DbalProcessStoreTest extends PostgresTestCase {

  private const EVENT = '6f1c2a7e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

  private FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
  }

  private function store(?Connection $c = null, int $strandedAfter = 900): DbalProcessStore {
    return new DbalProcessStore($c ?? $this->db, $this->clock, '', $strandedAfter);
  }

  public function test_insert_assigns_an_id_at_version_one_and_round_trips(): void {
    $p = OrderProcess::started(41);
    $p->mark_source('cli');
    $p->advance(status: 'running', payload: new OrderPayload('hello', 3));

    $id = $this->store()->insert($p);

    self::assertSame($id, $p->get_id());
    self::assertSame(1, $this->store()->version_of($id));
    $row = $this->db->fetchAssociative('SELECT * FROM ddd_processes WHERE id = ?', [$id]);
    self::assertNull($row['ignition_key']);
    self::assertSame(OrderProcess::class, $row['process_class']);
    self::assertSame('{"order_id":41,"channel":"web"}', $row['business_data']);

    $found = $this->store()->find($id);
    self::assertInstanceOf(OrderProcess::class, $found);
    self::assertNotSame($p, $found);
    self::assertSame(41, $found->orderId());
    self::assertSame('running', $found->status());
    self::assertSame('cli', $found->source());
    self::assertSame('0f6a5b8e-1d2c-4b3a-9e8f-7a6b5c4d3e2f', $found->correlation_id());
    self::assertSame(['reserve', 'charge'], $found->steps()->steps);
    self::assertSame('reserve', $found->current_step_name());
    self::assertInstanceOf(OrderPayload::class, $found->payload());
    self::assertSame(['hello', 3], [$found->payload()->note, $found->payload()->count]);
  }

  public function test_a_step_checkpoint_round_trips_and_is_readable_after_find(): void {
    // D3 dynamic AwaitAll reads its checkpointed key set after a reload (process.await-all-dynamic).
    $p = OrderProcess::started(7);
    $p->steps()->record_checkpoint('reserve', new OrderPayload('children', 2));
    $p->steps()->advance();
    $id = $this->store()->insert($p);

    $found = $this->store()->find($id);

    self::assertNotNull($found);
    $checkpoint = $found->steps()->checkpoint_for('reserve');
    self::assertInstanceOf(OrderPayload::class, $checkpoint);
    self::assertSame(['children', 2], [$checkpoint->note, $checkpoint->count]);
    self::assertSame(['reserve' => 'release'], $found->steps()->compensations);
    self::assertSame('charge', $found->current_step_name());
  }

  public function test_find_and_version_of_an_unknown_id_are_null(): void {
    self::assertNull($this->store()->find(999));
    self::assertNull($this->store()->version_of(999));
  }

  public function test_insert_ignited_sets_the_ignition_key_and_a_second_ignition_of_the_same_fact_is_already_ignited(): void {
    $first = OrderProcess::started(1);
    $first->mark_ignited_by(self::EVENT);

    self::assertSame(IgnitionResult::Inserted, $this->store()->insert_ignited($first, OrderProcess::class, self::EVENT));
    self::assertNotNull($first->get_id());
    self::assertSame(
      IgnitionKey::for(self::EVENT, OrderProcess::class),
      $this->db->fetchOne('SELECT ignition_key FROM ddd_processes WHERE id = ?', [$first->get_id()])
    );

    // Another worker (another session) delivers the same fact again.
    $loser = OrderProcess::started(1);
    $loser->mark_ignited_by(self::EVENT);
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store($this->secondConnection())->insert_ignited($loser, OrderProcess::class, self::EVENT));
    self::assertNull($loser->get_id(), 'the loser is not persisted');
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_processes'));
  }

  public function test_already_ignited_inside_an_open_transaction_leaves_it_usable(): void {
    $first = OrderProcess::started(1);
    $this->store()->insert_ignited($first, OrderProcess::class, self::EVENT);

    $this->db->beginTransaction();
    $loser = OrderProcess::started(1);
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store()->insert_ignited($loser, OrderProcess::class, self::EVENT));
    self::assertSame(1, (int) $this->db->fetchOne('SELECT 1'), 'ON CONFLICT DO NOTHING does not abort the transaction');
    $this->db->commit();
  }

  public function test_the_ignition_gate_is_transactional(): void {
    $this->db->beginTransaction();
    $p = OrderProcess::started(1);
    self::assertSame(IgnitionResult::Inserted, $this->store()->insert_ignited($p, OrderProcess::class, self::EVENT));
    $this->db->rollBack();

    $again = OrderProcess::started(1);
    self::assertSame(IgnitionResult::Inserted, $this->store($this->secondConnection())->insert_ignited($again, OrderProcess::class, self::EVENT));
  }

  public function test_one_fact_may_ignite_different_process_classes(): void {
    // Same key value under another class: the gate is the (class, key) pair.
    $this->db->executeStatement(
      "INSERT INTO ddd_processes (process_class, business_data, correlation_id, ignition_key) VALUES (?, '{}', 'c', ?)",
      ['Other\\Process', IgnitionKey::for(self::EVENT, OrderProcess::class)]
    );

    self::assertSame(IgnitionResult::Inserted, $this->store()->insert_ignited(OrderProcess::started(1), OrderProcess::class, self::EVENT));
  }

  public function test_manual_starts_are_never_deduped_even_with_the_same_ignited_by_event_id(): void {
    $a = OrderProcess::started(1);
    $a->mark_ignited_by(self::EVENT);
    $b = OrderProcess::started(1);
    $b->mark_ignited_by(self::EVENT);

    $this->store()->insert($a);
    $this->store()->insert($b);

    self::assertNotSame($a->get_id(), $b->get_id());
    self::assertSame(2, (int) $this->db->fetchOne("SELECT count(*) FROM ddd_processes WHERE ignited_by_event_id = ? AND ignition_key IS NULL", [self::EVENT]));
  }

  public function test_inserting_an_already_persisted_process_fails(): void {
    $p = OrderProcess::started(1);
    $this->store()->insert($p);

    $this->expectException(ProcessStoreFailed::class);
    $this->store()->insert($p);
  }

  public function test_save_is_version_fenced(): void {
    $p = OrderProcess::started(1);
    $id = $this->store()->insert($p);
    $p->advance_step();
    $p->advance(status: 'running', payload: new OrderPayload('two'));

    self::assertSame(2, $this->store()->save($p, 1));
    self::assertSame(2, $this->store()->version_of($id));
    self::assertSame('charge', $this->store()->find($id)->current_step_name());

    // A holder whose session lock vanished still believes version 1.
    $stale = OrderProcess::started(1);
    $stale->set_id($id);
    $stale->fail('stale write');
    try {
      $this->store($this->secondConnection())->save($stale, 1);
      self::fail('a stale save overwrote a newer state');
    } catch (ConcurrentProcessModification) {
    }
    self::assertSame('running', $this->store()->find($id)->status());
    self::assertSame(2, $this->store()->version_of($id));
  }

  public function test_save_of_an_unknown_or_unpersisted_process_fails(): void {
    $p = OrderProcess::started(1);
    try {
      $this->store()->save($p, 1);
      self::fail('saved a process without an id');
    } catch (ProcessStoreFailed) {
    }

    $p->set_id(12345);
    $this->expectException(ProcessStoreFailed::class);
    $this->store()->save($p, 1);
  }

  public function test_touch_bumps_the_version_and_is_fenced(): void {
    $id = $this->store()->insert(OrderProcess::started(1));

    self::assertSame(2, $this->store()->touch($id, 1));
    self::assertSame(3, $this->store()->touch($id, 2));

    try {
      $this->store()->touch($id, 2);
      self::fail('a stale touch passed the fence');
    } catch (ConcurrentProcessModification) {
    }

    $this->expectException(ProcessStoreFailed::class);
    $this->store()->touch(999, 1);
  }

  public function test_an_unknown_class_is_quarantined_with_status_failed_and_a_reason(): void {
    $id = $this->store()->insert(OrderProcess::started(1));
    $this->db->executeStatement('UPDATE ddd_processes SET process_class = ? WHERE id = ?', ['App\\Gone\\Process', $id]);

    try {
      $this->store()->find($id);
      self::fail('an undecodable row was returned');
    } catch (QuarantinedProcess $e) {
      self::assertStringContainsString('App\\Gone\\Process', $e->getMessage());
    }

    $row = $this->db->fetchAssociative('SELECT status, quarantine_reason, version FROM ddd_processes WHERE id = ?', [$id]);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('App\\Gone\\Process', (string) $row['quarantine_reason']);
    self::assertSame(2, (int) $row['version'], 'quarantine advances the version, so a stale holder cannot overwrite it');

    $this->expectException(QuarantinedProcess::class);
    $this->store()->find($id);
  }

  // ── D6: LargeString business data (CR-W4CE-5) ─────────────────────────────

  private function largeStringProcess(LargeString $blob, ?LargeString $optional = null): LargeStringProcess {
    $p = new LargeStringProcess($blob, $optional, 'big');
    $p->initialize_lifecycle('corr-ls', new ProcessSteps(['begin'], []));
    return $p;
  }

  public function test_a_one_megabyte_binary_large_string_round_trips_in_business_data(): void {
    $bytes = random_bytes(1024 * 1024);
    $id = $this->store()->insert($this->largeStringProcess(new LargeString($bytes)));

    $found = $this->store()->find($id);

    self::assertInstanceOf(LargeStringProcess::class, $found);
    self::assertSame($bytes, $found->blob->value);
    self::assertNull($found->optional);
    self::assertSame('big', $found->label);
    $stored = json_decode((string) $this->db->fetchOne('SELECT business_data FROM ddd_processes WHERE id = ?', [$id]), true);
    self::assertTrue(LargeString::is_encoded($stored['blob']), 'stored in the LargeString wire form (base64, length, sha256)');
  }

  public function test_a_nullable_large_string_round_trips_when_set(): void {
    $id = $this->store()->insert($this->largeStringProcess(new LargeString('a'), new LargeString("\x00\xff", 16)));

    $found = $this->store()->find($id);

    self::assertInstanceOf(LargeStringProcess::class, $found);
    self::assertSame("\x00\xff", $found->optional?->value);
    self::assertSame(16, $found->optional?->max_bytes);
  }

  public function test_a_corrupted_large_string_quarantines_the_row_with_its_reason(): void {
    $id = $this->store()->insert($this->largeStringProcess(new LargeString('payload')));
    $data = json_decode((string) $this->db->fetchOne('SELECT business_data FROM ddd_processes WHERE id = ?', [$id]), true);
    $data['blob']['data'] = base64_encode('tampered');
    $this->db->executeStatement('UPDATE ddd_processes SET business_data = ? WHERE id = ?', [json_encode($data), $id]);

    try {
      $this->store()->find($id);
      self::fail('an undecodable row was returned');
    } catch (QuarantinedProcess) {
    }

    $row = $this->db->fetchAssociative('SELECT status, quarantine_reason FROM ddd_processes WHERE id = ?', [$id]);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('LargeString length mismatch', (string) $row['quarantine_reason'], 'UndecodableLargeString::$quarantineReason');
  }

  public function test_broken_json_is_quarantined(): void {
    $id = $this->store()->insert(OrderProcess::started(1));
    $this->db->executeStatement("UPDATE ddd_processes SET steps = '{not json' WHERE id = ?", [$id]);

    try {
      $this->store()->find($id);
      self::fail('an undecodable row was returned');
    } catch (QuarantinedProcess) {
    }
    self::assertNotNull($this->db->fetchOne('SELECT quarantine_reason FROM ddd_processes WHERE id = ?', [$id]));
  }

  public function test_find_waiting_for_reads_the_waits_index_by_class_and_marker(): void {
    $byClass = OrderProcess::started(1);
    $this->store()->insert($byClass);
    $byClass->advance(status: 'suspended', waiting_for: PingFact::class, await_mechanism: new AwaitEvent(PingFact::class, ['n' => 1]));
    $this->store()->save($byClass, 1);

    $byMarker = OrderProcess::started(2);
    $byMarker->advance(status: 'suspended', waiting_for: PingMarker::class, await_mechanism: new MarkerAwait());
    $this->store()->insert($byMarker);

    $running = OrderProcess::started(3);
    $this->store()->insert($running);

    $ids = $this->store()->find_waiting_for(PingFact::class);
    sort($ids);
    self::assertSame([$byClass->get_id(), $byMarker->get_id()], $ids);
    self::assertSame([$byMarker->get_id()], $this->store()->find_waiting_for(PingMarker::class));
    self::assertSame([], $this->store()->find_waiting_for(\stdClass::class));

    $found = $this->store()->find((int) $byClass->get_id());
    self::assertEquals(new AwaitEvent(PingFact::class, ['n' => 1]), $found->await_mechanism());
    self::assertSame(['n' => 1], $found->match_criteria() ?? ['n' => 1]);

    // Resumed: the wait row goes with the same save.
    $found->advance(status: 'running');
    $this->store()->save($found, 2);
    self::assertSame([$byMarker->get_id()], $this->store()->find_waiting_for(PingFact::class));
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_process_waits'));
  }

  public function test_the_waits_index_commits_and_rolls_back_with_the_save(): void {
    $p = OrderProcess::started(1);
    $id = $this->store()->insert($p);

    $this->db->beginTransaction();
    $p->advance(status: 'suspended', waiting_for: PingFact::class, await_mechanism: new AwaitEvent(PingFact::class));
    $this->store()->save($p, 1);
    self::assertSame([$id], $this->store()->find_waiting_for(PingFact::class));
    $this->db->rollBack();

    self::assertSame([], $this->store()->find_waiting_for(PingFact::class));
    self::assertSame(1, $this->store()->version_of($id));
  }

  public function test_find_waiting_for_narrows_by_await_key_when_given(): void {
    $p = OrderProcess::started(1);
    $p->advance(status: 'suspended', waiting_for: PingFact::class, await_mechanism: new AwaitEvent(PingFact::class));
    $this->store()->insert($p);

    self::assertSame([$p->get_id()], $this->store()->find_waiting_for(PingFact::class, null));
    self::assertSame([], $this->store()->find_waiting_for(PingFact::class, 'order:9'));
  }

  public function test_the_store_declares_that_its_lookup_matches_fact_ancestry(): void {
    self::assertInstanceOf(IMatchesFactAncestry::class, $this->store());
  }

  public function test_a_keyed_await_writes_one_wait_row_with_its_key(): void {
    $p = OrderProcess::started(1);
    $p->advance(status: 'suspended', waiting_for: JobDone::class, await_mechanism: AwaitEvent::keyed(JobDone::class, 'job-1'));
    $this->store()->insert($p);

    self::assertSame([[JobDone::class, 'job-1']], $this->waitRows((int) $p->get_id()));
    self::assertSame([$p->get_id()], $this->store()->find_waiting_for(JobDone::class, 'job-1'));
    self::assertSame([], $this->store()->find_waiting_for(JobDone::class, 'job-2'));
    self::assertSame([], $this->store()->find_waiting_for(JobDone::class, ''), 'not an unkeyed row');
    self::assertSame([$p->get_id()], $this->store()->find_waiting_for(JobDone::class), 'null = any key');
  }

  public function test_an_any_of_await_writes_one_row_per_branch_class_and_key(): void {
    $p = OrderProcess::started(1);
    $any = AwaitAny::of(AwaitEvent::keyed(JobDone::class, 'job-1'))->cancelled_by(new AwaitEvent(AppDestroyed::class, ['app_id' => 4]));
    $p->advance(status: 'suspended', waiting_for: $any->event_class(), await_mechanism: $any);
    $this->store()->insert($p);
    $other = OrderProcess::started(2);
    $other->advance(status: 'suspended', waiting_for: PingFact::class, await_mechanism: new AwaitEvent(PingFact::class));
    $this->store()->insert($other);

    self::assertSame([[AppDestroyed::class, ''], [JobDone::class, 'job-1']], $this->waitRows((int) $p->get_id()));
    // The index names the branch classes, not the common ancestor in waiting_for:
    // a PingFact (also an IntegrationEvent) does not reach the any-of row.
    self::assertSame([$other->get_id()], $this->store()->find_waiting_for(PingFact::class));
    self::assertSame([$p->get_id()], $this->store()->find_waiting_for(AppDestroyed::class));
    self::assertSame([$p->get_id()], $this->store()->find_waiting_for(JobDone::class, 'job-1'));

    $found = $this->store()->find((int) $p->get_id());
    self::assertInstanceOf(AwaitAny::class, $found->await_mechanism());
    self::assertEquals($any->routes(), $found->await_routes());
  }

  public function test_a_keyed_await_all_keeps_one_row_per_missing_key(): void {
    $p = OrderProcess::started(1);
    $all = AwaitAll::keyed(ChildGone::class, ['c1', 'c2', 'c3'], 3600);
    $p->advance(status: 'suspended', waiting_for: ChildGone::class, await_mechanism: $all);
    $id = $this->store()->insert($p);
    self::assertSame([[ChildGone::class, 'c1'], [ChildGone::class, 'c2'], [ChildGone::class, 'c3']], $this->waitRows($id));

    $p->update_await($all->accumulate(new ChildGone('c2')));
    $this->store()->save($p, 1);

    self::assertSame([[ChildGone::class, 'c1'], [ChildGone::class, 'c3']], $this->waitRows($id));
    self::assertSame([], $this->store()->find_waiting_for(ChildGone::class, 'c2'));
    self::assertSame([$id], $this->store()->find_waiting_for(ChildGone::class, 'c3'));
  }

  public function test_an_alarm_writes_no_wait_row(): void {
    $p = OrderProcess::started(1);
    $p->advance(status: 'suspended', await_mechanism: AwaitAlarm::after(90000));
    $id = $this->store()->insert($p);

    self::assertSame([], $this->waitRows($id));
    self::assertSame('suspended', $this->db->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$id]));
    self::assertInstanceOf(AwaitAlarm::class, $this->store()->find($id)->await_mechanism());
  }

  /** @return list<array{0: string, 1: string}> (event_class, await_key) rows of one process, sorted */
  private function waitRows(int $id): array {
    return array_map(
      static fn (array $r) => [(string) $r['event_class'], (string) $r['await_key']],
      $this->db->fetchAllAssociative('SELECT event_class, await_key FROM ddd_process_waits WHERE process_id = ? ORDER BY event_class, await_key', [$id])
    );
  }

  public function test_find_stranded_reports_old_running_and_scheduled_rows_without_a_live_intent(): void {
    $old = $this->clock->now();
    $running = OrderProcess::started(1);
    $this->store()->insert($running);
    $scheduled = OrderProcess::started(2);
    $scheduled->advance(status: 'scheduled');
    $this->store()->insert($scheduled);
    $covered = OrderProcess::started(3);
    $covered->advance(status: 'scheduled');
    $this->store()->insert($covered);
    $suspended = OrderProcess::started(4);
    $suspended->advance(status: 'suspended', waiting_for: PingFact::class, await_mechanism: new AwaitEvent(PingFact::class));
    $this->store()->insert($suspended);

    $this->db->executeStatement(
      "INSERT INTO ddd_wakeups (idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at)
       VALUES (?, 'continue', 'acme', ?, 0, 'scheduled', now())",
      ['continue:' . $covered->get_id() . ':0', $covered->get_id()]
    );

    $exhausted = OrderProcess::started(6);
    $exhausted->advance(status: 'scheduled');
    $this->store()->insert($exhausted);
    $this->db->executeStatement(
      "INSERT INTO ddd_wakeups (idempotency_key, kind, consumer, process_id, step_index, expected_status, due_at, exhausted_at)
       VALUES (?, 'continue', 'acme', ?, 0, 'scheduled', now(), now())",
      ['continue:' . $exhausted->get_id() . ':0', $exhausted->get_id()]
    );

    $this->clock->advance('+16 minutes');
    $fresh = OrderProcess::started(5);
    $this->store()->insert($fresh);

    $stranded = $this->store()->find_stranded($this->clock->now());

    $byId = [];
    foreach ($stranded as $s) {
      $byId[$s->process_id] = $s;
    }
    ksort($byId);
    self::assertSame([$running->get_id(), $scheduled->get_id(), $exhausted->get_id()], array_keys($byId), 'an exhausted intent is not a live one');
    self::assertSame('running', $byId[$running->get_id()]->status);
    self::assertSame('scheduled', $byId[$scheduled->get_id()]->status);
    self::assertSame(OrderProcess::class, $byId[$scheduled->get_id()]->process_class);
    self::assertSame(0, $byId[$scheduled->get_id()]->step_index);
    self::assertEquals($old, $byId[$running->get_id()]->updated_at);
  }

  public function test_updated_at_comes_from_the_clock_on_every_write(): void {
    $p = OrderProcess::started(1);
    $id = $this->store()->insert($p);
    $this->clock->advance('+1 hour');
    $this->store()->touch($id, 1);

    self::assertSame(
      '2026-10-01 13:00:00+00',
      $this->db->fetchOne("SELECT to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM ddd_processes WHERE id = ?", [$id])
    );
  }

  public function test_uses_the_table_prefix(): void {
    PostgresDatabase::resetDddSchema($this->db, 'p_');
    try {
      $store = new DbalProcessStore($this->db, $this->clock, 'p_');
      $id = $store->insert(OrderProcess::started(1));
      self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM p_ddd_processes WHERE id = ?', [$id]));
      self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_processes'));
    } finally {
      foreach (\TangibleDDD\Symfony\Persistence\PostgresSchema::tables() as $t) {
        $this->db->executeStatement('DROP TABLE IF EXISTS p_' . $t . ' CASCADE');
      }
    }
  }

  public function test_a_storage_failure_is_process_store_failed(): void {
    $this->db->executeStatement('DROP TABLE ddd_processes CASCADE');

    $this->expectException(ProcessStoreFailed::class);
    $this->store()->insert(OrderProcess::started(1));
  }
}
