<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Ops;

use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Ops\DbalLedgerOperatorSource;
use TangibleDDD\Symfony\Ops\DbalWakeupOperatorSource;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/** D9: the delivery ledger and the wakeup intents as operator-view layers, Postgres 16. */
final class DbalOperatorSourcesTest extends PostgresTestCase {

  public function test_the_ledger_lists_failing_and_exhausted_pairs_against_the_budget(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_failed('listener:A', 'evt-1', 'smtp down', 2);
    $ledger->mark_failed('listener:B', 'evt-1', 'bad data', 5);
    $ledger->mark_exhausted('listener:B', 'evt-1');
    $ledger->mark_delivered('listener:C', 'evt-1');
    $ledger->mark_failed('listener:D', 'evt-2', 'flaky', 1);
    $ledger->mark_delivered('listener:D', 'evt-2'); // recovered

    $items = (new DbalLedgerOperatorSource($this->db, 'txp', '', 5))->items(null, 10);

    $byKey = [];
    foreach ($items as $item) {
      $byKey[$item->key] = $item;
    }
    self::assertSame(['listener:A@evt-1', 'listener:B@evt-1'], array_keys($byKey));
    self::assertSame(Layer::Delivery, $byKey['listener:A@evt-1']->layer);
    self::assertSame(2, $byKey['listener:A@evt-1']->attempts);
    self::assertSame(5, $byKey['listener:A@evt-1']->budget);
    self::assertSame('smtp down', $byKey['listener:A@evt-1']->last_error);
    self::assertSame('txp', $byKey['listener:B@evt-1']->consumer);
    self::assertStringContainsString('exhausted', (string) $byKey['listener:B@evt-1']->last_error);
    self::assertNotNull($byKey['listener:B@evt-1']->first_seen);
  }

  public function test_the_ledger_honours_layer_and_limit(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_failed('listener:A', 'evt-1', 'x', 1);
    $ledger->mark_failed('listener:B', 'evt-1', 'y', 1);
    $source = new DbalLedgerOperatorSource($this->db, 'txp');

    self::assertCount(1, $source->items(Layer::Delivery, 1));
    self::assertSame([], $source->items(Layer::Relay, 10));
    self::assertSame([], $source->items(null, 0));
  }

  public function test_wakeups_list_exhausted_and_failing_intents_of_the_consumer(): void {
    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $wakeups = new DbalWakeupScheduler($this->db);
    $boundary = new DbalTransactionBoundary($this->db);
    $boundary->run(function () use ($wakeups, $clock): void {
      $wakeups->schedule(WakeupIntent::timeout('txp', 7, 2, $clock->now()));
      $wakeups->schedule(WakeupIntent::timeout('txp', 8, 1, $clock->now()));
      $wakeups->schedule(WakeupIntent::timeout('other', 9, 1, $clock->now()));
    });
    $claims = $wakeups->claim_due($clock->now(), 10, 60);
    foreach ($claims as $claim) {
      if ($claim->intent->process_id === 7 || $claim->intent->process_id === 9) {
        $wakeups->exhaust($claim, 'lock busy x10');
      }
    }

    $items = (new DbalWakeupOperatorSource($this->db, 'txp', '', 10))->items(null, 10);

    self::assertCount(1, $items, 'the unfailed intent and the other consumer\'s are not listed');
    self::assertSame(Layer::Wakeup, $items[0]->layer);
    self::assertSame('timeout:7:2', $items[0]->key);
    self::assertSame(10, $items[0]->budget);
    self::assertSame('lock busy x10', $items[0]->last_error);
    self::assertSame(['rearm'], $items[0]->repairs);
    self::assertSame([], (new DbalWakeupOperatorSource($this->db, 'txp'))->items(Layer::Delivery, 10));
  }
}
