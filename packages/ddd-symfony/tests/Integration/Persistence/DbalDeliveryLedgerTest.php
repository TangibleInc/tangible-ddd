<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use Psr\Log\NullLogger;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;

final class DbalDeliveryLedgerTest extends PostgresTestCase {

  public function test_unknown_pair_is_undelivered_with_no_attempts(): void {
    $ledger = new DbalDeliveryLedger($this->db);

    self::assertFalse($ledger->delivered('s', 'e'));
    self::assertSame(0, $ledger->attempts('s', 'e'));
    self::assertNull($ledger->last_error('s', 'e'));
    self::assertFalse($ledger->exhausted('s', 'e'));
  }

  public function test_failures_count_attempts_and_keep_the_last_error(): void {
    $ledger = new DbalDeliveryLedger($this->db);

    $ledger->mark_failed('s', 'e', 'first', 1);
    $ledger->mark_failed('s', 'e', 'second', 2);

    self::assertFalse($ledger->delivered('s', 'e'));
    self::assertSame(2, $ledger->attempts('s', 'e'));
    self::assertSame('second', $ledger->last_error('s', 'e'));
    self::assertSame(0, $ledger->attempts('other', 'e'), 'per subscriber');
    self::assertSame(0, $ledger->attempts('s', 'other'), 'per event');
  }

  public function test_delivered_after_a_failure_keeps_the_attempt_count(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_failed('s', 'e', 'boom', 1);

    $ledger->mark_delivered('s', 'e');
    $ledger->mark_delivered('s', 'e'); // idempotent

    self::assertTrue($ledger->delivered('s', 'e'));
    self::assertSame(1, $ledger->attempts('s', 'e'));
    $row = $this->db->fetchAssociative("SELECT delivered_at, exhausted_at FROM ddd_delivery_ledger WHERE subscriber_id = 's'");
    self::assertNotNull($row['delivered_at']);
    self::assertNull($row['exhausted_at']);
  }

  public function test_exhausted_is_a_terminal_marker_separate_from_failures(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_failed('s', 'e', 'boom', 5);
    self::assertFalse($ledger->exhausted('s', 'e'), 'markFailed never writes the marker');

    $ledger->mark_exhausted('s', 'e');
    $ledger->mark_exhausted('s', 'e');

    self::assertTrue($ledger->exhausted('s', 'e'));
    self::assertSame(5, $ledger->attempts('s', 'e'));
    self::assertSame('boom', $ledger->last_error('s', 'e'));
    self::assertNotNull($this->db->fetchOne("SELECT exhausted_at FROM ddd_delivery_ledger WHERE subscriber_id = 's'"));
  }

  public function test_mark_exhausted_without_prior_failures_creates_the_row(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_exhausted('s', 'e');
    self::assertTrue($ledger->exhausted('s', 'e'));
  }

  public function test_drives_the_core_invoker_so_a_second_delivery_skips_the_delivered_subscriber(): void {
    $runs = ['a' => 0, 'b' => 0];
    $failB = true;
    $registry = new SubscriptionRegistry();
    $registry->add(new Subscriber('a', Subscriber::LISTENER, PingFact::class, function () use (&$runs) {
      $runs['a']++;
    }));
    $registry->add(new Subscriber('b', Subscriber::LISTENER, PingFact::class, function () use (&$runs, &$failB) {
      $runs['b']++;
      if ($failB) {
        $failB = false;
        throw new \RuntimeException('b fails once');
      }
    }));
    $delivery = new IntegrationDelivery($registry, new DbalDeliveryLedger($this->db), 5, new NullLogger());
    $wrapped = IntegrationEnvelope::wrap(['n' => 1], 'corr', 1, 'evt-1');

    $first = $delivery->deliver(PingFact::class, $wrapped);
    $second = $delivery->deliver(PingFact::class, $wrapped);

    self::assertSame(['a'], $first->delivered);
    self::assertSame(['b'], $first->failed);
    self::assertSame(['a'], $second->skipped);
    self::assertSame(['b'], $second->delivered);
    self::assertSame(['a' => 1, 'b' => 2], $runs);
  }
}
