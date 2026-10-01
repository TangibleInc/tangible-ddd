<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\FrozenClock;

abstract class PdoDeliveryLedgerCases extends PdoTestCase {

  private const EVENT = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private FrozenClock $clock;
  private PdoDeliveryLedger $ledger;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    $this->ledger = new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock);
  }

  public function test_an_unknown_pair_is_undelivered_with_no_attempts(): void {
    self::assertInstanceOf(IDeliveryLedger::class, $this->ledger);
    self::assertFalse($this->ledger->delivered('listener:a', self::EVENT));
    self::assertSame(0, $this->ledger->attempts('listener:a', self::EVENT));
    self::assertNull($this->ledger->lastError('listener:a', self::EVENT));
    self::assertFalse($this->ledger->exhausted('listener:a', self::EVENT));
  }

  public function test_failures_record_the_attempt_and_last_error_per_subscriber(): void {
    $this->ledger->markFailed('listener:a', self::EVENT, 'first', 1);
    $this->clock->advance('PT30S');
    $this->ledger->markFailed('listener:a', self::EVENT, 'second', 2);
    $this->ledger->markFailed('listener:b', self::EVENT, 'other', 1);

    self::assertSame(2, $this->ledger->attempts('listener:a', self::EVENT));
    self::assertSame('second', $this->ledger->lastError('listener:a', self::EVENT));
    self::assertSame(1, $this->ledger->attempts('listener:b', self::EVENT));
    self::assertFalse($this->ledger->delivered('listener:a', self::EVENT));
    self::assertSame('2026-10-01 12:00:30.000000', $this->row('ddd_delivery_ledger', 'subscriber_id = ?', ['listener:a'])['updated_at']);
  }

  public function test_mark_delivered_after_a_failure_keeps_the_count_and_clears_the_error(): void {
    $this->ledger->markFailed('listener:a', self::EVENT, 'flaky', 1);
    $this->ledger->markDelivered('listener:a', self::EVENT);
    $this->ledger->markDelivered('listener:a', self::EVENT);

    self::assertTrue($this->ledger->delivered('listener:a', self::EVENT));
    self::assertSame(1, $this->ledger->attempts('listener:a', self::EVENT));
    self::assertNull($this->ledger->lastError('listener:a', self::EVENT));
    self::assertSame('2026-10-01 12:00:00.000000', $this->row('ddd_delivery_ledger', 'subscriber_id = ?', ['listener:a'])['delivered_at']);
  }

  public function test_exhaustion_is_a_separate_idempotent_terminal_marker(): void {
    for ($i = 1; $i <= 5; $i++) {
      $this->ledger->markFailed('listener:a', self::EVENT, "fail $i", $i);
    }
    self::assertFalse($this->ledger->exhausted('listener:a', self::EVENT), 'markFailed never writes the marker');

    $this->ledger->markExhausted('listener:a', self::EVENT);
    $this->clock->advance('PT1H');
    $this->ledger->markExhausted('listener:a', self::EVENT);

    self::assertTrue($this->ledger->exhausted('listener:a', self::EVENT));
    self::assertSame('fail 5', $this->ledger->lastError('listener:a', self::EVENT));
    self::assertSame('2026-10-01 12:00:00.000000', $this->row('ddd_delivery_ledger', 'subscriber_id = ?', ['listener:a'])['exhausted_at'], 'first marker kept');
    self::assertTrue((new PdoDeliveryLedger($this->db, self::PREFIX))->exhausted('listener:a', self::EVENT));
  }

  public function test_ledger_writes_roll_back_with_the_subscribers_transaction(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    try {
      $boundary->run(function () {
        $this->ledger->markDelivered('listener:a', self::EVENT);
        throw new \RuntimeException('listener command failed at commit');
      });
    } catch (\RuntimeException) {
    }
    self::assertFalse($this->ledger->delivered('listener:a', self::EVENT));
  }

  public function test_long_subscriber_ids_fit(): void {
    $id = 'listener:' . str_repeat('App\\Very\\Long\\Namespace\\', 9) . 'Listener';
    self::assertGreaterThan(200, strlen($id));
    $this->ledger->markDelivered($id, self::EVENT);
    self::assertTrue($this->ledger->delivered($id, self::EVENT));
  }
}
