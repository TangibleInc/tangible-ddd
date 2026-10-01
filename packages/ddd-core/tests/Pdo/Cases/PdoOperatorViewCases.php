<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoOperatorView;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

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
    [$dead, $retrying] = $store->claim(2, $this->clock->now(), 60);
    $store->deadLetter($dead, 'transport rejected');
    $store->retryLater($retrying, 'transport down', $this->clock->now()->modify('+1 minute'));

    $ledger = new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock);
    $ledger->markFailed('listener:a', self::EVENT, 'listener threw', 2);
    $ledger->markFailed('listener:b', self::EVENT, 'gave up', 5);
    $ledger->markExhausted('listener:b', self::EVENT);
    $ledger->markDelivered('listener:ok', self::EVENT);

    $jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    (new PdoTransactionBoundary($this->db))->run(function () use ($jobs) {
      // Process ids far above anything the tests insert, so no stranded row matches them.
      $jobs->schedule(WakeupIntent::timeout('acme', 900077, 0, $this->clock->now()));
      $jobs->schedule(WakeupIntent::continuation('acme', 900078, 0, $this->clock->now()->modify('+1 day')));
    });
    [$wake] = $jobs->claimDue($this->clock->now(), 1, 60);
    $jobs->retryLater($wake, 'LockNotAcquired', $this->clock->now()->modify('+2 seconds'));

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

  public function test_it_lists_every_layer_with_attempts_against_budget(): void {
    $ids = $this->seed();

    $items = $this->view()->list();

    $byKey = [];
    foreach ($items as $item) {
      $byKey[$item['layer'] . ':' . $item['key']] = $item;
      self::assertSame('acme', $item['consumer']);
      self::assertSame(['layer', 'consumer', 'key', 'attempts', 'budget', 'last_error', 'first_seen', 'repair_actions', 'detail'], array_keys($item));
    }
    self::assertEqualsCanonicalizing([
      'relay:dead', 'relay:retrying',
      'delivery:listener:a', 'delivery:listener:b',
      'wakeup:timeout:900077:0',
      "process:{$ids['stranded']}", "process:{$ids['quarantined']}",
    ], array_keys($byKey));

    $dead = $byKey['relay:dead'];
    self::assertSame(1, $dead['attempts']);
    self::assertSame(5, $dead['budget']);
    self::assertSame('transport rejected', $dead['last_error']);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $dead['first_seen']);
    self::assertSame(['retry', 'replay', 'discard'], $dead['repair_actions']);
    self::assertSame(['retry'], $byKey['relay:retrying']['repair_actions']);

    self::assertSame(2, $byKey['delivery:listener:a']['attempts']);
    self::assertSame(self::EVENT, $byKey['delivery:listener:a']['detail']['event_id']);
    self::assertSame('exhausted', $byKey['delivery:listener:b']['detail']['state']);
    self::assertSame('retrying', $byKey['delivery:listener:a']['detail']['state']);

    self::assertSame(1, $byKey['wakeup:timeout:900077:0']['attempts']);
    self::assertSame(10, $byKey['wakeup:timeout:900077:0']['budget']);
    self::assertSame('LockNotAcquired', $byKey['wakeup:timeout:900077:0']['last_error']);

    self::assertSame(['resume_stranded', 'fail_stranded'], $byKey["process:{$ids['stranded']}"]['repair_actions']);
    self::assertSame('stranded', $byKey["process:{$ids['stranded']}"]['detail']['state']);
    self::assertSame('quarantined', $byKey["process:{$ids['quarantined']}"]['detail']['state']);
    self::assertStringContainsString('App\\Gone', (string) $byKey["process:{$ids['quarantined']}"]['last_error']);
  }

  public function test_it_filters_by_layer_and_honours_the_limit(): void {
    $this->seed();
    $view = $this->view();

    self::assertSame(['relay', 'relay'], array_column($view->list('relay'), 'layer'));
    self::assertSame(['delivery', 'delivery'], array_column($view->list('delivery'), 'layer'));
    self::assertCount(1, $view->list('wakeup'));
    self::assertCount(2, $view->list('process'));
    self::assertCount(3, $view->list(null, 3));
    self::assertSame([], $view->list('workflow'));

    $this->expectException(\InvalidArgumentException::class);
    $view->list('nonsense');
  }

  public function test_a_healthy_system_lists_nothing(): void {
    $this->store()->append(self::record('fine'));
    self::assertSame([], $this->view()->list());
  }
}
