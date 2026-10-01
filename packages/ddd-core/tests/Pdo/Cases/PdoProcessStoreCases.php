<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Core\Tests\Pdo\Support\LargeStringProcess;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Core\Tests\Unit\Fixtures\AppDestroyScheduled;
use TangibleDDD\Core\Tests\Unit\Fixtures\BillingFact;
use TangibleDDD\Core\Tests\Unit\Fixtures\ChildPurged;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OnboardingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\MemberJoined;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\VipJoined;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IMatchesFactAncestry;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

abstract class PdoProcessStoreCases extends PdoTestCase {

  private const EVENT = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';
  private const OTHER_EVENT = '7d1f0b52-3a0e-4c55-8f3e-2a9b6c1d4e77';

  private FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
  }

  private function store(?IHostConnection $db = null): PdoProcessStore {
    return new PdoProcessStore($db ?? $this->db, self::PREFIX, $this->clock);
  }

  private function process(int $order = 3, string $status = 'running', ?string $waitingFor = null): FulfilmentProcess {
    $p = new FulfilmentProcess($order);
    $p->initialize_lifecycle('corr-1', new ProcessSteps(['begin', 'ship'], ['ship' => 'unship']));
    if ($status !== 'running' || $waitingFor !== null) {
      $mechanism = $waitingFor !== null && is_a($waitingFor, \TangibleDDD\Domain\Events\IIntegrationEvent::class, true)
        ? new AwaitEvent($waitingFor, ['user_id' => 9])
        : null;
      $p->advance($status, waiting_for: $waitingFor, await_mechanism: $mechanism);
    }
    return $p;
  }

  public function test_insert_assigns_an_id_starts_at_version_one_and_round_trips(): void {
    $store = $this->store();
    self::assertInstanceOf(IProcessStore::class, $store);
    $p = $this->process(42);
    $p->mark_source('web');
    $p->advance_step();

    $id = $store->insert($p);

    self::assertSame($id, $p->get_id());
    self::assertSame(1, $store->versionOf($id));
    $row = $this->row('ddd_processes', 'id = ?', [$id]);
    self::assertNull($row['ignition_key']);
    self::assertNull($row['quarantine_reason']);
    self::assertSame(FulfilmentProcess::class, $row['process_class']);
    self::assertSame('2026-10-01 12:00:00.000000', $row['created_at']);

    $found = $store->find($id);
    self::assertInstanceOf(FulfilmentProcess::class, $found);
    self::assertNotSame($p, $found);
    self::assertSame(42, $found->order_id);
    self::assertSame($id, $found->get_id());
    self::assertSame('running', $found->status());
    self::assertSame('corr-1', $found->correlation_id());
    self::assertSame('web', $found->source());
    self::assertSame(1, $found->current_step_index());
    self::assertSame('ship', $found->current_step_name());
    self::assertSame('unship', $found->compensation_for('ship'));
  }

  public function test_find_of_an_unknown_id_is_null(): void {
    self::assertNull($this->store()->find(987654));
    self::assertNull($this->store()->versionOf(987654));
  }

  public function test_insert_of_an_already_persisted_process_fails(): void {
    $store = $this->store();
    $p = $this->process();
    $store->insert($p);

    $this->expectException(ProcessStoreFailed::class);
    $store->insert($p);
  }

  public function test_insert_ignited_sets_the_ignition_key_and_a_second_ignition_is_already_ignited(): void {
    $store = $this->store();
    $first = $this->process();
    $first->mark_ignited_by(self::EVENT);

    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($first, FulfilmentProcess::class, self::EVENT));
    self::assertSame(IgnitionKey::for(self::EVENT, FulfilmentProcess::class), $this->row('ddd_processes', 'id = ?', [$first->get_id()])['ignition_key']);

    $loser = $this->process();
    $other = $this->store($this->otherConnection());
    self::assertSame(IgnitionResult::AlreadyIgnited, $other->insertIgnited($loser, FulfilmentProcess::class, self::EVENT));
    self::assertNull($loser->get_id(), 'the loser is not persisted');
    self::assertSame(1, $this->countRows('ddd_processes'));

    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($this->process(), FulfilmentProcess::class, self::OTHER_EVENT), 'another fact ignites again');
    $onboarding = new OnboardingProcess();
    $onboarding->initialize_lifecycle('corr-2', new ProcessSteps(['begin'], []));
    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($onboarding, OnboardingProcess::class, self::EVENT), 'another class ignites on the same fact');
  }

  public function test_a_concurrent_uncommitted_ignition_blocks_the_second_worker_which_never_inserts(): void {
    $store = $this->store();
    $this->db->begin();
    $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT);

    $other = $this->otherConnection();
    $other->execute('SET SESSION innodb_lock_wait_timeout = 1');
    $loser = $this->process();
    try {
      $this->store($other)->insertIgnited($loser, FulfilmentProcess::class, self::EVENT);
      self::fail('expected the unique-index wait to time out');
    } catch (ProcessStoreFailed $e) {
      self::assertStringContainsString('1205', $e->getMessage(), 'lock wait timeout: retryable, not AlreadyIgnited');
    } finally {
      $this->db->commit();
    }
    self::assertNull($loser->get_id());
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store($other)->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
    self::assertSame(1, $this->countRows('ddd_processes'));
  }

  public function test_an_ignition_that_lost_inside_a_transaction_leaves_the_transaction_usable(): void {
    $store = $this->store();
    $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT);

    (new PdoTransactionBoundary($this->db))->run(function () use ($store) {
      self::assertSame(IgnitionResult::AlreadyIgnited, $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
      $store->insert($this->process(9));
    });
    self::assertSame(2, $this->countRows('ddd_processes'));
  }

  public function test_an_ignition_rolled_back_with_its_transaction_does_not_block_a_retry(): void {
    $store = $this->store();
    try {
      (new PdoTransactionBoundary($this->db))->run(function () use ($store) {
        $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT);
        throw new \RuntimeException('initial save failed');
      });
    } catch (\RuntimeException) {
    }

    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
  }

  public function test_other_integrity_errors_are_store_failures_never_already_ignited(): void {
    $faulty = new FaultyConnection($this->db);
    $notNull = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'x' cannot be null");
    $notNull->errorInfo = ['23000', 1048, "Column 'x' cannot be null"];
    $faulty->failStatement = '/INSERT INTO `tp_ddd_processes`/';
    $faulty->failWith = $notNull;

    $this->expectException(ProcessStoreFailed::class);
    $this->store($faulty)->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT);
  }

  public function test_manual_starts_are_never_deduped_even_with_an_ignited_by_event_id(): void {
    $store = $this->store();
    $a = $this->process();
    $a->mark_ignited_by(self::EVENT);
    $b = $this->process();
    $b->mark_ignited_by(self::EVENT);

    $store->insert($a);
    $store->insert($b);
    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
    self::assertSame(IgnitionResult::AlreadyIgnited, $store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));

    self::assertSame(3, $this->countRows('ddd_processes', 'process_class = ?', [FulfilmentProcess::class]));
    self::assertSame(2, $this->countRows('ddd_processes', 'ignited_by_event_id = ? AND ignition_key IS NULL', [self::EVENT]));
  }

  public function test_save_is_version_fenced(): void {
    $store = $this->store();
    $p = $this->process();
    $id = $store->insert($p);
    $this->clock->advance('PT1M');

    $p->advance('suspended', waiting_for: UserJoined::class, await_mechanism: new AwaitEvent(UserJoined::class, ['user_id' => 9]));
    self::assertSame(2, $store->save($p, 1));
    self::assertSame(2, $store->versionOf($id));
    self::assertSame('2026-10-01 12:01:00.000000', $this->row('ddd_processes', 'id = ?', [$id])['updated_at']);

    $stale = $this->store($this->otherConnection());
    try {
      $stale->save($p, 1);
      self::fail('expected ConcurrentProcessModification');
    } catch (ConcurrentProcessModification) {
    }
    self::assertSame(2, $store->versionOf($id), 'nothing overwritten');

    $found = $store->find($id);
    self::assertSame('suspended', $found->status());
    self::assertSame(UserJoined::class, $found->waiting_for());
    self::assertEquals(new AwaitEvent(UserJoined::class, ['user_id' => 9]), $found->await_mechanism());
  }

  public function test_save_of_an_unknown_or_unpersisted_process_fails(): void {
    $p = $this->process();
    try {
      $this->store()->save($p, 1);
      self::fail('expected ProcessStoreFailed');
    } catch (ProcessStoreFailed) {
    }
    $p->set_id(424242);
    $this->expectException(ProcessStoreFailed::class);
    $this->store()->save($p, 1);
  }

  public function test_touch_bumps_the_version_under_the_fence(): void {
    $store = $this->store();
    $id = $store->insert($this->process());

    self::assertSame(2, $store->touch($id, 1));
    self::assertSame(3, $store->touch($id, 2));
    try {
      $store->touch($id, 2);
      self::fail('expected ConcurrentProcessModification');
    } catch (ConcurrentProcessModification) {
    }
    $this->expectException(ProcessStoreFailed::class);
    $store->touch(424242, 1);
  }

  public function test_an_undecodable_row_is_quarantined_and_the_worker_continues(): void {
    $store = $this->store();
    $id = $store->insert($this->process());
    $healthy = $store->insert($this->process(8));
    $this->db->execute('UPDATE tp_ddd_processes SET process_class = ? WHERE id = ?', ['App\\Gone\\RemovedProcess', $id]);

    try {
      $store->find($id);
      self::fail('expected QuarantinedProcess');
    } catch (QuarantinedProcess $e) {
      self::assertStringContainsString('App\\Gone\\RemovedProcess', $e->getMessage());
    }

    $row = $this->row('ddd_processes', 'id = ?', [$id]);
    self::assertSame('failed', $row['status'], 'no new status value (R5)');
    self::assertStringContainsString('no longer exists', (string) $row['quarantine_reason']);
    self::assertSame(2, (int) $row['version'], 'a stale holder cannot resurrect it');
    self::assertSame(8, $store->find($healthy)->order_id);
  }

  public function test_corrupt_json_is_quarantined_too(): void {
    $store = $this->store();
    $id = $store->insert($this->process());
    $this->db->execute('UPDATE tp_ddd_processes SET steps = ? WHERE id = ?', ['{not json', $id]);

    $this->expectException(QuarantinedProcess::class);
    try {
      $store->find($id);
    } finally {
      self::assertNotNull($this->row('ddd_processes', 'id = ?', [$id])['quarantine_reason']);
    }
  }

  public function test_find_waiting_for_returns_ids_of_suspended_processes_matching_by_is_a(): void {
    $store = $this->store();
    $exact = $store->insert($this->process(1, 'suspended', UserJoined::class));
    $marker = $store->insert($this->process(2, 'suspended', BillingFact::class));
    $store->insert($this->process(3, 'running'));
    $store->insert($this->process(4, 'suspended', OrderPlaced::class));
    $completed = $this->process(5, 'suspended', UserJoined::class);
    $store->insert($completed);
    $completed->complete();
    $store->save($completed, 1);

    self::assertSame([$exact], $store->findWaitingFor(UserJoined::class));
    $billing = $store->findWaitingFor(OrderPlaced::class);
    sort($billing);
    self::assertSame([$marker, $marker + 2], $billing, 'OrderPlaced implements BillingFact (D2)');
    self::assertSame([], $store->findWaitingFor('App\\Unknown\\Fact'));
  }

  /** A FulfilmentProcess suspended on $await, as the runner leaves it. */
  private function suspendedOn(IAwaitMechanism $await, int $order = 1): FulfilmentProcess {
    $p = $this->process($order);
    $class = $await->event_class();
    $p->advance('suspended', waiting_for: $class === '' ? null : $class, await_mechanism: $await);
    return $p;
  }

  /** @return list<array{event_class: string, await_key: string}> */
  private function waitsOf(int $id): array {
    return array_map(
      static fn (array $r) => ['event_class' => (string) $r['event_class'], 'await_key' => (string) $r['await_key']],
      $this->db->fetchAll('SELECT event_class, await_key FROM `' . $this->table('ddd_process_waits') . '` WHERE process_id = ? ORDER BY event_class, await_key', [$id])
    );
  }

  public function test_the_store_declares_that_it_matches_fact_ancestry(): void {
    self::assertInstanceOf(IMatchesFactAncestry::class, $this->store(), 'W4P-R6: one lookup per fact');
  }

  public function test_keyed_awaits_are_indexed_per_route_and_found_by_their_key(): void {
    $store = $this->store();
    $one = $store->insert($this->suspendedOn(AwaitEvent::keyed(JobFinished::class, 'job-1'), 1));
    $two = $store->insert($this->suspendedOn(AwaitEvent::keyed(JobFinished::class, 'job-2'), 2));
    $unkeyed = $store->insert($this->suspendedOn(new AwaitEvent(JobFinished::class), 3));

    self::assertSame([['event_class' => JobFinished::class, 'await_key' => 'job-1']], $this->waitsOf($one));
    self::assertSame([$one], $store->findWaitingFor(JobFinished::class, 'job-1'));
    self::assertSame([$two], $store->findWaitingFor(JobFinished::class, 'job-2'));
    self::assertSame([], $store->findWaitingFor(JobFinished::class, 'job-3'));
    self::assertSame([$unkeyed], $store->findWaitingFor(JobFinished::class, ''), "'' = unkeyed rows only");
    self::assertSame([$one, $two, $unkeyed], $store->findWaitingFor(JobFinished::class), 'null = any key');
  }

  public function test_saving_rewrites_the_routes_and_a_process_that_is_not_suspended_has_none(): void {
    $store = $this->store();
    $p = $this->suspendedOn(AwaitAll::keyed(ChildPurged::class, ['c1', 'c2'], 3600));
    $id = $store->insert($p);
    self::assertSame([
      ['event_class' => ChildPurged::class, 'await_key' => 'c1'],
      ['event_class' => ChildPurged::class, 'await_key' => 'c2'],
    ], $this->waitsOf($id));

    $gather = $p->await_mechanism();
    self::assertInstanceOf(AwaitAll::class, $gather);
    $p->update_await($gather->accumulate(new ChildPurged('c1')));
    $version = $store->save($p, 1);
    self::assertSame([['event_class' => ChildPurged::class, 'await_key' => 'c2']], $this->waitsOf($id), 'a partial arrival leaves the missing key');
    self::assertSame([], $store->findWaitingFor(ChildPurged::class, 'c1'));
    self::assertSame([$id], $store->findWaitingFor(ChildPurged::class, 'c2'));

    $p->advance('running');
    $store->save($p, $version);
    self::assertSame([], $this->waitsOf($id));
    self::assertSame([], $store->findWaitingFor(ChildPurged::class));
  }

  public function test_an_any_of_await_is_found_by_each_branch_class_and_not_by_its_common_ancestor(): void {
    $store = $this->store();
    $any = AwaitAny::of(AwaitEvent::keyed(JobFinished::class, 'job-9'))
      ->cancelledBy(new AwaitEvent(AppDestroyScheduled::class, ['app_id' => 4]));
    $id = $store->insert($this->suspendedOn($any));

    self::assertSame([$id], $store->findWaitingFor(JobFinished::class, 'job-9'));
    self::assertSame([$id], $store->findWaitingFor(AppDestroyScheduled::class, ''));
    self::assertSame([$id], $store->findWaitingFor(AppDestroyScheduled::class));
    self::assertSame([], $store->findWaitingFor(UserJoined::class), 'the waiting_for column holds the common ancestor; the routes are exact');
  }

  public function test_a_parent_class_route_matches_a_subclass_fact(): void {
    require_once dirname(__DIR__, 2) . '/Unit/Fixtures/Process/Wave4Processes.php';
    $store = $this->store();
    $id = $store->insert($this->suspendedOn(new AwaitEvent(MemberJoined::class)));

    self::assertSame([$id], $store->findWaitingFor(VipJoined::class));
    self::assertSame([$id], $store->findWaitingFor(VipJoined::class, ''));
    self::assertSame([], $store->findWaitingFor(VipJoined::class, 'some-key'));
  }

  public function test_a_suspended_row_without_routes_is_still_found_by_its_waiting_for_column(): void {
    $store = $this->store();
    $id = $store->insert($this->suspendedOn(new AwaitEvent(UserJoined::class)));
    $this->db->execute('DELETE FROM `' . $this->table('ddd_process_waits') . '` WHERE process_id = ?', [$id]); // written before 007

    self::assertSame([$id], $store->findWaitingFor(UserJoined::class));
    self::assertSame([$id], $store->findWaitingFor(UserJoined::class, ''));
    self::assertSame([], $store->findWaitingFor(UserJoined::class, 'k'), 'a keyed lookup needs a route');
  }

  public function test_a_pure_alarm_has_no_route(): void {
    $store = $this->store();
    $id = $store->insert($this->suspendedOn(AwaitAlarm::after(90000)));

    self::assertSame([], $this->waitsOf($id));
    self::assertNull($this->row('ddd_processes', 'id = ?', [$id])['waiting_for']);
  }

  public function test_routes_are_written_in_the_callers_transaction(): void {
    $store = $this->store();
    $boundary = new PdoTransactionBoundary($this->db);
    try {
      $boundary->run(function () use ($store): void {
        $store->insert($this->suspendedOn(AwaitEvent::keyed(JobFinished::class, 'job-1')));
        throw new \DomainException('rolled back');
      });
    } catch (\DomainException) {
    }

    self::assertSame(0, $this->countRows('ddd_processes'));
    self::assertSame(0, $this->countRows('ddd_process_waits'));
  }

  // ── D6: LargeString business data (codec.large-payload, decode.unknown-class) ──

  private function largeStringProcess(LargeString $blob, ?LargeString $optional = null): LargeStringProcess {
    $p = new LargeStringProcess($blob, $optional, 'big');
    $p->initialize_lifecycle('corr-ls', new ProcessSteps(['begin'], []));
    return $p;
  }

  public function test_a_one_megabyte_binary_large_string_round_trips(): void {
    $bytes = random_bytes(1024 * 1024);
    $store = $this->store();
    $id = $store->insert($this->largeStringProcess(new LargeString($bytes)));

    $found = $store->find($id);

    self::assertInstanceOf(LargeStringProcess::class, $found);
    self::assertSame($bytes, $found->blob->value);
    self::assertNull($found->optional);
    self::assertSame('big', $found->label);
    $stored = json_decode((string) $this->row('ddd_processes', 'id = ?', [$id])['business_data'], true);
    self::assertTrue(LargeString::isEncoded($stored['blob']), 'stored in the LargeString wire form, base64 with sha256');
  }

  public function test_a_nullable_large_string_round_trips_when_set(): void {
    $store = $this->store();
    $id = $store->insert($this->largeStringProcess(new LargeString('a'), new LargeString("\x00\xff", 16)));

    $found = $store->find($id);

    self::assertSame("\x00\xff", $found?->optional?->value);
  }

  public function test_a_corrupted_large_string_quarantines_the_row_with_its_reason(): void {
    $store = $this->store();
    $id = $store->insert($this->largeStringProcess(new LargeString('payload')));
    $data = json_decode((string) $this->row('ddd_processes', 'id = ?', [$id])['business_data'], true);
    $data['blob']['data'] = base64_encode('tampered');
    $this->db->execute('UPDATE `' . $this->table('ddd_processes') . '` SET business_data = ? WHERE id = ?', [json_encode($data), $id]);

    try {
      $store->find($id);
      self::fail('expected QuarantinedProcess');
    } catch (QuarantinedProcess) {
    }

    $row = $this->row('ddd_processes', 'id = ?', [$id]);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('LargeString length mismatch', (string) $row['quarantine_reason']);
  }

  public function test_find_stranded_reports_old_running_or_scheduled_rows_with_no_live_intent(): void {
    $store = $this->store();
    $jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    $stranded = $store->insert($this->process(1, 'running'));
    $scheduled = $store->insert($this->process(2, 'scheduled'));
    $withIntent = $store->insert($this->process(3, 'scheduled'));
    $store->insert($this->process(4, 'suspended', UserJoined::class));
    (new PdoTransactionBoundary($this->db))->run(fn () => $jobs->schedule(WakeupIntent::continuation('acme', $withIntent, 0, $this->clock->now())));
    $this->clock->advance('PT15M');
    $fresh = $store->insert($this->process(5, 'running'));

    $found = $store->findStranded($this->clock->now());

    self::assertSame([$stranded, $scheduled], array_map(static fn (StrandedProcess $s) => $s->processId, $found));
    self::assertSame('running', $found[0]->status);
    self::assertSame(FulfilmentProcess::class, $found[0]->processClass);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $found[0]->updatedAt);
    self::assertNotContains($fresh, array_map(static fn (StrandedProcess $s) => $s->processId, $found));
  }
}
