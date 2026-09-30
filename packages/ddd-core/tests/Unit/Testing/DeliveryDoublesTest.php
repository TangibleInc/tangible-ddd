<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\NullRelayWakeup;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryEffectJournal;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\RecordingRelayWakeup;
use TangibleDDD\Testing\StaticSubscriberProbe;

final class DeliveryDoublesTest extends TestCase {

  public function test_ledger_tracks_delivery_and_attempts_per_pair(): void {
    $l = new InMemoryDeliveryLedger();
    self::assertInstanceOf(IDeliveryLedger::class, $l);

    self::assertFalse($l->delivered('s', 'e'));
    self::assertSame(0, $l->attempts('s', 'e'));

    $l->markFailed('s', 'e', 'boom', 1);
    $l->markFailed('s', 'e', 'boom2', 2);
    self::assertSame(2, $l->attempts('s', 'e'));
    self::assertSame('boom2', $l->lastError('s', 'e'));
    self::assertSame(0, $l->attempts('s', 'other'));
    self::assertSame(0, $l->attempts('other', 'e'));

    $l->markDelivered('s', 'e');
    self::assertTrue($l->delivered('s', 'e'));
    self::assertSame(2, $l->attempts('s', 'e'), 'attempts survive delivery for the operator view');
    self::assertFalse($l->delivered('other', 'e'));
  }

  public function test_effect_journal_find_store_invalidate(): void {
    $j = new InMemoryEffectJournal();
    self::assertInstanceOf(IEffectJournal::class, $j);

    self::assertNull($j->find('charge:1'));
    $j->store('charge:1', $r = new EffectResult(['id' => 'ch_1'], 'ch_1'));
    self::assertSame($r, $j->find('charge:1'));

    $j->invalidate('charge:1', 'repair #7');
    self::assertNull($j->find('charge:1'));
    self::assertSame([['key' => 'charge:1', 'reason' => 'repair #7']], $j->invalidations);

    $j->invalidate('unknown', 'noop'); // no throw
    self::assertCount(2, $j->invalidations);
  }

  private function claim(): \TangibleDDD\Runtime\Outbox\Claim {
    $clock = new FrozenClock();
    $store = new InMemoryOutboxStore($clock);
    $store->append(new OutboxRecord('e1', 't', 'acme_integration_t', null, null, null, [], $clock->now()));
    return $store->claim(1, $clock->now(), 60)[0];
  }

  public function test_transport_records_submissions_with_their_absolute_due_time(): void {
    $t = new InMemoryTransport();
    self::assertInstanceOf(ITransport::class, $t);
    $due = new \DateTimeImmutable('2026-10-01 12:05:00', new \DateTimeZone('UTC'));

    $ref = $t->submit($this->claim(), ['x' => 1, '__event_id' => 'e1'], $due);

    self::assertNotNull($ref);
    self::assertCount(1, $t->submissions);
    self::assertSame('e1', $t->submissions[0]['event_id']);
    self::assertSame($due, $t->submissions[0]['due_at']);
    self::assertSame(['x' => 1, '__event_id' => 'e1'], $t->submissions[0]['envelope']);
  }

  public function test_transport_rejection_and_missing_ref_controls(): void {
    $t = new InMemoryTransport();
    $t->rejectNext(new TransportRejected('queue full'));
    try {
      $t->submit($this->claim(), [], new \DateTimeImmutable());
      self::fail('expected TransportRejected');
    } catch (TransportRejected) {
    }

    $t->returnNoRefNext();
    self::assertNull($t->submit($this->claim(), [], new \DateTimeImmutable()));
    self::assertNotNull($t->submit($this->claim(), [], new \DateTimeImmutable()));
    self::assertCount(2, $t->submissions, 'rejected submissions are not recorded');
  }

  public function test_transport_connection_sharing_is_configurable(): void {
    $store = new InMemoryOutboxStore(new FrozenClock());

    self::assertFalse((new InMemoryTransport())->sharesConnectionWith($store));
    self::assertTrue((new InMemoryTransport(sharesConnection: true))->sharesConnectionWith($store));
  }

  public function test_relay_wakeups(): void {
    $null = new NullRelayWakeup();
    self::assertInstanceOf(IRelayWakeup::class, $null);
    $null->poke('acme');

    $rec = new RecordingRelayWakeup();
    $rec->poke('acme');
    $rec->poke('acme');
    self::assertSame(['acme', 'acme'], $rec->pokes);
  }

  public function test_subscriber_probe_answers_known_actions_and_null_otherwise(): void {
    $p = new StaticSubscriberProbe(['acme_integration_order_placed' => true, 'acme_integration_x' => false]);
    self::assertInstanceOf(ISubscriberProbe::class, $p);

    self::assertTrue($p->hasSubscribers('acme_integration_order_placed'));
    self::assertFalse($p->hasSubscribers('acme_integration_x'));
    self::assertNull($p->hasSubscribers('acme_integration_unknown'));
  }
}
