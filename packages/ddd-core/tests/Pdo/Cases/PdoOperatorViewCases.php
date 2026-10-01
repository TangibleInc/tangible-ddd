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
    $store->deadLetter($dead, 'transport rejected');
    $store->retryLater($retrying, 'transport down', $this->clock->now()->modify('+1 minute'));

    $ledger = new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock);
    $ledger->markFailed('listener:a', self::EVENT, 'listener threw', 2);
    $ledger->markFailed('listener:b', self::EVENT, 'gave up', 5);
    $ledger->markExhausted('listener:b', self::EVENT);
    $ledger->markDelivered('listener:ok', self::EVENT);

    $jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    (new PdoTransactionBoundary($this->db))->run(function () use ($jobs, $store, $fact) {
      // Process ids far above anything the tests insert, so no stranded row matches them.
      $jobs->schedule(WakeupIntent::timeout('acme', 900077, 0, $this->clock->now()));
      $jobs->schedule(WakeupIntent::continuation('acme', 900078, 0, $this->clock->now()->modify('+1 day')));
      $store->accept($fact, $jobs->submit($fact, ['__event_id' => 'fact'], $this->clock->now()));
    });
    foreach ($jobs->claimDue($this->clock->now(), 2, 60) as $claim) {
      $jobs->retryLater($claim, $claim->intent->idempotencyKey === 'deliver:fact' ? 'subscribers to retry: listener:x' : 'LockNotAcquired', $this->clock->now()->modify('+2 seconds'));
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
    self::assertSame([1, 5, 'transport rejected'], [$dead->attempts, $dead->budget, $dead->lastError]);
    self::assertSame(['retry', 'replay', 'discard'], $dead->repairActions);
    self::assertSame(['retry'], $byKey['relay:retrying']->repairActions);
    self::assertSame('transport down', $byKey['relay:retrying']->lastError);

    $a = $byKey['delivery:listener:a@' . self::EVENT];
    self::assertSame([2, 5, 'listener threw', ['redeliver']], [$a->attempts, $a->budget, $a->lastError, $a->repairActions]);
    self::assertSame([], $byKey['delivery:listener:b@' . self::EVENT]->repairActions, 'exhausted: compensated, nothing to repair');
    self::assertSame([1, 5], [$byKey['delivery:deliver:fact']->attempts, $byKey['delivery:deliver:fact']->budget]);
    self::assertStringContainsString('listener:x', (string) $byKey['delivery:deliver:fact']->lastError);

    $wake = $byKey['wakeup:timeout:900077:0'];
    self::assertSame([1, 10, 'LockNotAcquired', ['retry_wake']], [$wake->attempts, $wake->budget, $wake->lastError, $wake->repairActions]);

    self::assertSame(['resume_stranded', 'fail_stranded'], $byKey["process:{$ids['stranded']}"]->repairActions);
    self::assertStringContainsString('App\\Gone', (string) $byKey["process:{$ids['quarantined']}"]->lastError);
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

    $rows = $this->view()->toArrays(Layer::Relay);

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
