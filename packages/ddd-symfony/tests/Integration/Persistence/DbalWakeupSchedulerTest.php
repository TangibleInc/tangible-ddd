<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Testing\RecordingRelayWakeup;

/**
 * IWakeupScheduler on the ddd_wakeups intent table (register 3.6, 5.3, D7).
 */
final class DbalWakeupSchedulerTest extends PostgresTestCase {

  private \DateTimeImmutable $t0;

  protected function setUp(): void {
    parent::setUp();
    $this->t0 = new \DateTimeImmutable('2026-10-01T12:00:00Z');
  }

  private function scheduler(?Connection $c = null, ?RecordingRelayWakeup $wakeup = null): DbalWakeupScheduler {
    return new DbalWakeupScheduler($c ?? $this->db, '', $wakeup);
  }

  private function inTx(callable $fn): void {
    $this->db->beginTransaction();
    $fn();
    $this->db->commit();
  }

  private function intentRows(): int {
    return (int) $this->db->fetchOne('SELECT count(*) FROM ddd_wakeups');
  }

  public function test_schedule_outside_a_transaction_is_refused(): void {
    $this->expectException(WakeupOutsideTransaction::class);
    $this->scheduler()->schedule(WakeupIntent::continuation('acme', 1, 0, $this->t0));
  }

  public function test_cancel_outside_a_transaction_is_refused(): void {
    $this->expectException(WakeupOutsideTransaction::class);
    $this->scheduler()->cancel('continue:1:0');
  }

  public function test_an_intent_commits_and_rolls_back_with_the_transaction(): void {
    $this->db->beginTransaction();
    $this->scheduler()->schedule(WakeupIntent::timeout('acme', 1, 2, $this->t0));
    $this->db->rollBack();
    self::assertSame(0, $this->intentRows());

    $this->inTx(fn () => $this->scheduler()->schedule(WakeupIntent::timeout('acme', 1, 2, $this->t0)));
    self::assertSame(1, $this->intentRows());
  }

  public function test_scheduling_an_existing_key_is_a_no_op(): void {
    $this->inTx(function (): void {
      $this->scheduler()->schedule(WakeupIntent::timeout('acme', 1, 2, $this->t0));
      $this->scheduler()->schedule(WakeupIntent::timeout('acme', 1, 2, $this->t0->modify('+1 day')));
    });

    self::assertSame(1, $this->intentRows());
    [$claimed] = $this->scheduler()->claimDue($this->t0, 10, 60);
    self::assertEquals($this->t0, $claimed->intent->dueAt);
  }

  public function test_claim_due_returns_only_due_intents_oldest_first_with_every_field(): void {
    $this->inTx(function (): void {
      $s = $this->scheduler();
      $s->schedule(WakeupIntent::timeout('acme', 7, 3, $this->t0->modify('+10 seconds')));
      $s->schedule(WakeupIntent::continuation('acme', 8, 0, $this->t0->modify('-5 seconds')));
      $s->schedule(WakeupIntent::continuation('acme', 9, 1, $this->t0->modify('+1 hour')));
      $s->schedule(new WakeupIntent(WakeKind::ResumeRetry, 'acme', 10, null, null, $this->t0, 'resume_retry:10'));
    });

    $claimed = $this->scheduler()->claimDue($this->t0->modify('+10 seconds'), 10, 60);

    self::assertSame(['continue:8:0', 'resume_retry:10', 'timeout:7:3'], array_map(static fn ($w) => $w->intent->idempotencyKey, $claimed));
    $timeout = $claimed[2];
    self::assertSame(WakeKind::Timeout, $timeout->intent->kind);
    self::assertSame('acme', $timeout->intent->consumer);
    self::assertSame(7, $timeout->intent->processId);
    self::assertSame(3, $timeout->intent->stepIndex);
    self::assertSame('suspended', $timeout->intent->expectedStatus);
    self::assertEquals($this->t0->modify('+10 seconds'), $timeout->intent->dueAt);
    self::assertSame('UTC', $timeout->intent->dueAt->getTimezone()->getName());
    self::assertSame(0, $timeout->attempts);
    self::assertEquals($this->t0->modify('+70 seconds'), $timeout->leaseUntil);
    self::assertNull($claimed[1]->intent->stepIndex);
    self::assertNull($claimed[1]->intent->expectedStatus);
  }

  public function test_claim_due_honours_the_limit(): void {
    $this->inTx(function (): void {
      for ($i = 1; $i <= 5; $i++) {
        $this->scheduler()->schedule(WakeupIntent::continuation('acme', $i, 0, $this->t0));
      }
    });

    self::assertCount(2, $this->scheduler()->claimDue($this->t0, 2, 60));
    self::assertCount(3, $this->scheduler()->claimDue($this->t0, 10, 60));
    self::assertCount(0, $this->scheduler()->claimDue($this->t0, 10, 60));
  }

  public function test_a_leased_intent_is_not_claimed_again_until_the_lease_expires(): void {
    $this->inTx(fn () => $this->scheduler()->schedule(WakeupIntent::continuation('acme', 1, 0, $this->t0)));

    [$first] = $this->scheduler()->claimDue($this->t0, 10, 60);
    self::assertSame([], $this->scheduler($this->secondConnection())->claimDue($this->t0->modify('+59 seconds'), 10, 60));

    [$again] = $this->scheduler($this->secondConnection())->claimDue($this->t0->modify('+60 seconds'), 10, 60);
    self::assertNotSame($first->claimToken, $again->claimToken);

    self::assertFalse($this->scheduler()->complete($first), 'the first holder lost its lease');
    self::assertSame(1, $this->intentRows());
    self::assertTrue($this->scheduler()->complete($again));
    self::assertSame(0, $this->intentRows());
  }

  public function test_complete_is_fenced_on_the_claim_token(): void {
    $this->inTx(fn () => $this->scheduler()->schedule(WakeupIntent::continuation('acme', 1, 0, $this->t0)));
    [$w] = $this->scheduler()->claimDue($this->t0, 10, 60);

    $forged = new \TangibleDDD\Runtime\Scheduling\ClaimedWakeup($w->intent, 'not-the-token', $w->leaseUntil, 0);
    self::assertFalse($this->scheduler()->complete($forged));
    self::assertTrue($this->scheduler()->complete($w));
    self::assertFalse($this->scheduler()->complete($w), 'completing twice is a lost lease, not an error');
  }

  public function test_retry_later_counts_an_attempt_and_gates_the_next_claim(): void {
    $this->inTx(fn () => $this->scheduler()->schedule(WakeupIntent::continuation('acme', 1, 0, $this->t0)));
    [$w] = $this->scheduler()->claimDue($this->t0, 10, 300);

    self::assertTrue($this->scheduler()->retryLater($w, 'lock busy', $this->t0->modify('+4 seconds')));
    self::assertFalse($this->scheduler()->retryLater($w, 'again', $this->t0->modify('+4 seconds')), 'the lease was released');

    self::assertSame([], $this->scheduler()->claimDue($this->t0->modify('+3 seconds'), 10, 60));
    [$retry] = $this->scheduler()->claimDue($this->t0->modify('+4 seconds'), 10, 60);
    self::assertSame(1, $retry->attempts);
    self::assertSame('lock busy', $this->db->fetchOne('SELECT last_error FROM ddd_wakeups'));
  }

  public function test_cancel_removes_the_intent_inside_the_transaction(): void {
    $this->inTx(function (): void {
      $this->scheduler()->schedule(WakeupIntent::timeout('acme', 1, 0, $this->t0));
      $this->scheduler()->schedule(WakeupIntent::timeout('acme', 2, 0, $this->t0));
    });

    $this->inTx(fn () => $this->scheduler()->cancel('timeout:1:0'));
    $this->inTx(fn () => $this->scheduler()->cancel('timeout:does-not-exist'));

    self::assertSame(['timeout:2:0'], $this->db->fetchFirstColumn('SELECT idempotency_key FROM ddd_wakeups'));
  }

  public function test_claim_due_refuses_to_join_an_open_transaction(): void {
    $this->db->beginTransaction();
    try {
      $this->expectException(NestedTransactionRejected::class);
      $this->scheduler()->claimDue($this->t0, 10, 60);
    } finally {
      $this->db->rollBack();
    }
  }

  public function test_two_workers_never_claim_the_same_intent(): void {
    $this->inTx(function (): void {
      for ($i = 1; $i <= 6; $i++) {
        $this->scheduler()->schedule(WakeupIntent::continuation('acme', $i, 0, $this->t0));
      }
    });

    $a = $this->scheduler()->claimDue($this->t0, 3, 60);
    $b = $this->scheduler($this->secondConnection())->claimDue($this->t0, 10, 60);

    $keys = array_map(static fn ($w) => $w->intent->idempotencyKey, array_merge($a, $b));
    self::assertCount(6, $keys);
    self::assertCount(6, array_unique($keys));
  }

  public function test_schedule_pokes_the_relay_wakeup_inside_the_transaction(): void {
    $wakeup = new RecordingRelayWakeup();
    $this->inTx(fn () => $this->scheduler(null, $wakeup)->schedule(WakeupIntent::continuation('acme', 1, 0, $this->t0)));

    self::assertSame(['acme'], $wakeup->pokes);
  }
}
