<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Core\Tests\Unit\Fixtures\BillingFact;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OnboardingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
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
