<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoJobsOperatorSource;
use TangibleDDD\Defaults\Pdo\PdoLedgerOperatorSource;
use TangibleDDD\Defaults\Pdo\PdoOperatorView;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * PdoOperatorView = core PortOperatorView over the pdo administration and
 * process store, plus IOperatorItemSource adapters for the jobs and ledger
 * tables (W3C-R5, CR-PDO-4).
 */
abstract class PdoOperatorViewCases extends OutboxTestCase {

  private const EVENT = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private function view(): PdoOperatorView {
    return new PdoOperatorView($this->db, 'acme', self::PREFIX, $this->clock);
  }

  private function process(string $status): FulfilmentProcess {
    $p = new FulfilmentProcess(1);
    $p->initialize_lifecycle('corr', new ProcessSteps(['begin'], []));
    if ($status !== 'running') {
      $p->advance($status);
    }
    return $p;
  }

  /** Seeds one item per layer and some healthy rows that must not show. */
  private function seed(): array {
    $store = $this->store();
    $store->append(self::record('dead'));
    $store->append(self::record('retrying'));
    $store->append(self::record('healthy'));
    $store->append(self::record('fact'));
    [$dead, $retrying, , $fact] = $store->claim(4, $this->clock->now(), 60);
    $store->dead_letter($dead, 'transport rejected');
    $store->retry_later($retrying, 'transport down', $this->clock->now()->modify('+1 minute'));

    $ledger = new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock);
    $ledger->mark_failed('listener:a', self::EVENT, 'listener threw', 2);
    $ledger->mark_failed('listener:b', self::EVENT, 'gave up', 5);
    $ledger->mark_exhausted('listener:b', self::EVENT);
    $ledger->mark_delivered('listener:ok', self::EVENT);

    $jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    (new PdoTransactionBoundary($this->db))->run(function () use ($jobs, $store, $fact) {
      // Process ids far above anything the tests insert, so no stranded row matches them.
      $jobs->schedule(WakeupIntent::timeout('acme', 900077, 0, $this->clock->now()));
      $jobs->schedule(WakeupIntent::continuation('acme', 900078, 0, $this->clock->now()->modify('+1 day')));
      $store->accept($fact, $jobs->submit($fact, ['__event_id' => 'fact'], $this->clock->now()));
    });
    foreach ($jobs->claim_due($this->clock->now(), 2, 60) as $claim) {
      $jobs->retry_later($claim, $claim->intent->key === 'deliver:fact' ? 'subscribers to retry: listener:x' : 'LockNotAcquired', $this->clock->now()->modify('+2 seconds'));
    }

    $processes = new PdoProcessStore($this->db, self::PREFIX, $this->clock);
    $stranded = $processes->insert($this->process('running'));
    $quarantined = $processes->insert($this->process('suspended'));
    $processes->insert($this->process('completed'));
    $this->db->execute('UPDATE tp_ddd_processes SET process_class = ? WHERE id = ?', ['App\\Gone', $quarantined]);
    try {
      $processes->find($quarantined);
    } catch (\Throwable) {
    }
    $this->clock->advance('PT20M');

    return ['stranded' => $stranded, 'quarantined' => $quarantined];
  }

  /** @param list<OperatorItem> $items @return array<string, OperatorItem> */
  private static function byKey(array $items): array {
    $out = [];
    foreach ($items as $item) {
      $out[$item->layer->value . ':' . $item->key] = $item;
    }
    return $out;
  }

  public function test_it_lists_every_layer_as_operator_items_with_attempts_against_budget(): void {
    $ids = $this->seed();

    $view = $this->view();
    self::assertInstanceOf(IOperatorView::class, $view);
    $items = $view->list();

    foreach ($items as $item) {
      self::assertInstanceOf(OperatorItem::class, $item);
      self::assertSame('acme', $item->consumer);
    }
    $byKey = self::byKey($items);
    self::assertSame([
      'relay:dead', 'relay:retrying',
      'delivery:listener:a@' . self::EVENT, 'delivery:listener:b@' . self::EVENT, 'delivery:deliver:fact',
      'wakeup:timeout:900077:0',
      "process:{$ids['stranded']}", "process:{$ids['quarantined']}",
    ], array_keys($byKey), 'ordered by layer, then first seen');

    $dead = $byKey['relay:dead'];
    self::assertSame([1, 5, 'transport rejected'], [$dead->attempts, $dead->budget, $dead->last_error]);
    self::assertSame(['retry', 'replay', 'discard'], $dead->repairs);
    self::assertSame(['retry'], $byKey['relay:retrying']->repairs);
    self::assertSame('transport down', $byKey['relay:retrying']->last_error);

    $a = $byKey['delivery:listener:a@' . self::EVENT];
    self::assertSame([2, 5, 'listener threw', ['redeliver']], [$a->attempts, $a->budget, $a->last_error, $a->repairs]);
    self::assertSame([], $byKey['delivery:listener:b@' . self::EVENT]->repairs, 'exhausted: compensated, nothing to repair');
    self::assertSame([1, 5], [$byKey['delivery:deliver:fact']->attempts, $byKey['delivery:deliver:fact']->budget]);
    self::assertStringContainsString('listener:x', (string) $byKey['delivery:deliver:fact']->last_error);

    $wake = $byKey['wakeup:timeout:900077:0'];
    self::assertSame([1, 10, 'LockNotAcquired', ['retry_wake']], [$wake->attempts, $wake->budget, $wake->last_error, $wake->repairs]);

    self::assertSame(['resume_stranded', 'fail_stranded'], $byKey["process:{$ids['stranded']}"]->repairs);
    self::assertStringContainsString('App\\Gone', (string) $byKey["process:{$ids['quarantined']}"]->last_error);
  }

  // ── repairs (register 3.10, C23; wave 4) ────────────────────────────────

  /** @return array<string, OperatorItem> */
  private function items(): array {
    return self::byKey($this->view()->list());
  }

  public function test_relay_retry_puts_a_dead_letter_back_to_pending(): void {
    $this->seed();
    $dead = $this->items()['relay:dead'];

    $this->view()->repair_item($dead, 'retry');

    $row = $this->row('ddd_outbox', 'event_id = ?', ['dead']);
    self::assertSame(['pending', 0], [$row['status'], (int) $row['attempts']]);
    self::assertSame(0, $this->countRows('ddd_dlq'));
    self::assertArrayNotHasKey('relay:dead', $this->items());
  }

  public function test_relay_replay_keeps_the_event_id_and_discard_drops_the_dead_letter(): void {
    $this->seed();
    $this->view()->repair(Layer::Relay, 'dead', 'replay');
    self::assertSame('pending', $this->row('ddd_outbox', 'event_id = ?', ['dead'])['status']);
    self::assertSame(0, $this->countRows('ddd_dlq'));

    $store = $this->store();
    [$again] = array_values(array_filter($store->claim(10, $this->clock->now(), 60), static fn ($c) => $c->event_id === 'dead'));
    $store->dead_letter($again, 'rejected again');
    $this->view()->repair(Layer::Relay, 'dead', 'discard');
    self::assertSame(0, $this->countRows('ddd_dlq'));
    self::assertSame('dlq', $this->row('ddd_outbox', 'event_id = ?', ['dead'])['status'], 'discard deletes the DLQ row only');
  }

  public function test_replay_of_an_event_without_a_dead_letter_is_refused(): void {
    $this->seed();
    $this->expectException(\TangibleDDD\Runtime\Outbox\OutboxRowNotFound::class);
    $this->view()->repair(Layer::Relay, 'retrying', 'replay');
  }

  public function test_retry_wake_makes_a_failed_intent_due_now(): void {
    $this->seed();
    $this->db->execute("UPDATE tp_ddd_jobs SET next_attempt_at = '2026-10-01 18:00:00' WHERE idempotency_key = 'timeout:900077:0'");

    $this->view()->repair_item($this->items()['wakeup:timeout:900077:0'], 'retry_wake');

    $row = $this->row('ddd_jobs', 'idempotency_key = ?', ['timeout:900077:0']);
    self::assertSame('2026-10-01 12:20:00.000000', $row['next_attempt_at']);
    self::assertSame(1, (int) $row['attempts'], 'the attempt history stays');
  }

  public function test_redeliver_makes_the_facts_deliver_job_due_now(): void {
    $this->seed();
    $this->db->execute("UPDATE tp_ddd_jobs SET next_attempt_at = '2026-10-01 18:00:00' WHERE idempotency_key = 'deliver:fact'");
    (new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock))->mark_failed('listener:x', 'fact', 'boom', 1);

    $this->view()->repair(Layer::Delivery, 'listener:x@fact', 'redeliver');
    self::assertSame('2026-10-01 12:20:00.000000', $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:fact'])['next_attempt_at']);

    $this->db->execute("UPDATE tp_ddd_jobs SET next_attempt_at = '2026-10-01 18:00:00' WHERE idempotency_key = 'deliver:fact'");
    $this->view()->repair_item($this->items()['delivery:deliver:fact'], 'redeliver');
    self::assertSame('2026-10-01 12:20:00.000000', $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:fact'])['next_attempt_at']);
  }

  public function test_redeliver_without_a_queued_deliver_job_is_refused(): void {
    $this->seed();
    $this->expectException(\TangibleDDD\Defaults\Pdo\PdoRepairRefused::class);
    $this->view()->repair(Layer::Delivery, 'listener:a@' . self::EVENT, 'redeliver');
  }

  public function test_a_leased_job_is_not_repaired(): void {
    $this->seed();
    $jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    $this->db->execute("UPDATE tp_ddd_jobs SET next_attempt_at = '2026-10-01 12:00:00' WHERE idempotency_key = 'timeout:900077:0'");
    $jobs->claim_due($this->clock->now(), 10, 300);

    $this->expectException(\TangibleDDD\Defaults\Pdo\PdoRepairRefused::class);
    $this->view()->repair(Layer::Wakeup, 'timeout:900077:0', 'retry_wake');
  }

  public function test_resume_stranded_writes_a_resume_retry_intent_and_drops_out_of_the_view(): void {
    $ids = $this->seed();

    $this->view()->repair_item($this->items()["process:{$ids['stranded']}"], 'resume_stranded');

    $job = $this->db->fetch_one('SELECT kind, expected_status FROM tp_ddd_jobs WHERE process_id = ?', [$ids['stranded']]);
    self::assertSame(['resume_retry', 'running'], [$job['kind'], $job['expected_status']]);
    self::assertArrayNotHasKey("process:{$ids['stranded']}", $this->items(), 'it has a live intent now');
  }

  public function test_fail_stranded_needs_a_reason_and_fails_the_process(): void {
    $ids = $this->seed();
    $item = $this->items()["process:{$ids['stranded']}"];
    try {
      $this->view()->repair_item($item, 'fail_stranded');
      self::fail('a reason is required');
    } catch (\InvalidArgumentException) {
    }

    $this->view()->repair_item($item, 'fail_stranded', ['reason' => 'worker lost']);

    $row = $this->row('ddd_processes', 'id = ?', [$ids['stranded']]);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('worker lost', (string) $row['last_error']);
  }

  public function test_a_stranded_repair_is_refused_while_another_session_holds_the_process_lock(): void {
    $ids = $this->seed();
    $other = $this->otherConnection();
    $name = \TangibleDDD\Defaults\Pdo\MySqlNamedLock::name_of(new \TangibleDDD\Runtime\Lock\LockKey('acme', '', $ids['stranded']));
    $other->fetch_one('SELECT GET_LOCK(?, 0) AS l', [$name]);
    try {
      $this->expectException(\TangibleDDD\Application\Process\Repair\ProcessNotStranded::class);
      $this->view()->repair(Layer::Process, (string) $ids['stranded'], 'resume_stranded');
    } finally {
      $other->fetch_one('SELECT RELEASE_LOCK(?) AS l', [$name]);
    }
  }

  public function test_an_action_the_layer_does_not_offer_is_rejected(): void {
    $this->seed();
    $this->expectException(\InvalidArgumentException::class);
    $this->view()->repair(Layer::Wakeup, 'timeout:900077:0', 'replay');
  }

  public function test_repair_item_refuses_an_action_the_item_does_not_list(): void {
    $this->seed();
    $this->expectException(\TangibleDDD\Defaults\Pdo\PdoRepairRefused::class);
    $this->view()->repair_item($this->items()['delivery:listener:b@' . self::EVENT], 'redeliver');
  }

  public function test_it_filters_by_layer_and_honours_the_limit(): void {
    $this->seed();
    $view = $this->view();

    $layers = static fn (array $items) => array_map(static fn (OperatorItem $i) => $i->layer, $items);
    self::assertSame([Layer::Relay, Layer::Relay], $layers($view->list(Layer::Relay)));
    self::assertSame([Layer::Delivery, Layer::Delivery, Layer::Delivery], $layers($view->list(Layer::Delivery)));
    self::assertCount(1, $view->list(Layer::Wakeup));
    self::assertCount(2, $view->list(Layer::Process));
    self::assertCount(3, $view->list(null, 3));
    self::assertSame([], $view->list(Layer::Workflow));
  }

  public function test_the_array_form_is_what_a_host_renders(): void {
    $this->seed();

    $rows = $this->view()->to_arrays(Layer::Relay);

    self::assertCount(2, $rows);
    self::assertSame(['layer', 'layer_label', 'consumer', 'key', 'attempts', 'budget', 'last_error', 'first_seen', 'repair_actions'], array_keys($rows[0]));
    self::assertSame('relay', $rows[0]['layer']);
    self::assertSame('2026-10-01T12:00:00+00:00', $rows[0]['first_seen']);
  }

  public function test_the_jobs_and_ledger_sources_stand_alone(): void {
    $this->seed();
    $jobs = new PdoJobsOperatorSource($this->db, 'acme', self::PREFIX);
    $ledger = new PdoLedgerOperatorSource($this->db, 'acme', self::PREFIX);
    self::assertInstanceOf(IOperatorItemSource::class, $jobs);
    self::assertInstanceOf(IOperatorItemSource::class, $ledger);

    self::assertSame(['timeout:900077:0'], array_map(static fn (OperatorItem $i) => $i->key, $jobs->items(Layer::Wakeup, 10)));
    self::assertSame(['deliver:fact'], array_map(static fn (OperatorItem $i) => $i->key, $jobs->items(Layer::Delivery, 10)));
    self::assertCount(2, $jobs->items(null, 10));
    self::assertCount(1, $jobs->items(null, 1));
    self::assertSame([], $jobs->items(Layer::Relay, 10));
    self::assertCount(2, $ledger->items(Layer::Delivery, 10));
    self::assertSame([], $ledger->items(Layer::Wakeup, 10));
  }

  public function test_a_healthy_system_lists_nothing(): void {
    $this->store()->append(self::record('fine'));
    self::assertSame([], $this->view()->list());
  }
}
