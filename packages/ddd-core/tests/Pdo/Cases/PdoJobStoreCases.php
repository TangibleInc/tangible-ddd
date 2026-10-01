<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Defaults\Pdo\DeliveryJob;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Testing\InMemoryOutboxStore;

abstract class PdoJobStoreCases extends OutboxTestCase {

  private function jobs(?IHostConnection $db = null): PdoJobStore {
    return new PdoJobStore($db ?? $this->db, 'acme', self::PREFIX, $this->clock);
  }

  private function inTx(callable $work): mixed {
    return (new PdoTransactionBoundary($this->db))->run($work);
  }

  /** @return list<string> */
  private static function keys(array $claims): array {
    return array_map(static fn (ClaimedWakeup $w) => $w->intent->idempotencyKey, $claims);
  }

  // ── IWakeupScheduler ──────────────────────────────────────────────────────

  public function test_schedule_and_cancel_refuse_to_run_outside_a_transaction(): void {
    $jobs = $this->jobs();
    self::assertInstanceOf(IWakeupScheduler::class, $jobs);

    foreach ([
      fn () => $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now())),
      fn () => $jobs->cancel('timeout:1:0'),
    ] as $call) {
      try {
        $call();
        self::fail('expected WakeupOutsideTransaction');
      } catch (WakeupOutsideTransaction) {
      }
    }
    self::assertSame(0, $this->countRows('ddd_jobs'));
  }

  public function test_an_intent_commits_and_rolls_back_with_the_process_transaction(): void {
    $jobs = $this->jobs();
    $this->inTx(fn () => $jobs->schedule(WakeupIntent::continuation('acme', 1, 2, $this->clock->now())));
    try {
      $this->inTx(function () use ($jobs) {
        $jobs->schedule(WakeupIntent::timeout('acme', 1, 2, $this->clock->now()));
        throw new \RuntimeException('process save failed');
      });
    } catch (\RuntimeException) {
    }

    self::assertSame(['continue:1:2'], array_column($this->db->fetchAll('SELECT idempotency_key FROM tp_ddd_jobs'), 'idempotency_key'));
  }

  public function test_scheduling_an_existing_key_is_a_no_op(): void {
    $jobs = $this->jobs();
    $this->inTx(function () use ($jobs) {
      $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, self::utc('2026-10-02 12:00:00')));
      $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, self::utc('2030-01-01 00:00:00')));
    });

    self::assertSame(1, $this->countRows('ddd_jobs'));
    self::assertSame('2026-10-02 12:00:00.000000', $this->row('ddd_jobs', 'idempotency_key = ?', ['timeout:1:0'])['due_at']);
  }

  public function test_due_at_is_absolute_utc_and_the_intent_round_trips(): void {
    $jobs = $this->jobs();
    $due = new \DateTimeImmutable('2026-10-02 09:00:00.5', new \DateTimeZone('Europe/Berlin'));
    $intent = new WakeupIntent(WakeKind::ResumeRetry, 'acme', 12, 3, 'suspended', $due, 'resume_retry:12:3');
    $this->inTx(fn () => $jobs->schedule($intent));

    self::assertSame('2026-10-02 07:00:00.500000', $this->row('ddd_jobs', 'idempotency_key = ?', ['resume_retry:12:3'])['due_at']);
    self::assertSame([], $jobs->claimDue(self::utc('2026-10-02 07:00:00.4'), 10, 60));
    [$claimed] = $jobs->claimDue(self::utc('2026-10-02 07:00:00.5'), 10, 60);

    self::assertEquals($intent, $claimed->intent);
    self::assertSame(0, $claimed->attempts);
    self::assertEquals(self::utc('2026-10-02 07:01:00.5'), $claimed->leaseUntil);
  }

  public function test_claim_due_leases_due_intents_oldest_first_up_to_the_limit(): void {
    $jobs = $this->jobs();
    $this->inTx(function () use ($jobs) {
      $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, self::utc('2026-10-01 11:00:00')));
      $jobs->schedule(WakeupIntent::continuation('acme', 2, 0, self::utc('2026-10-01 10:00:00')));
      $jobs->schedule(WakeupIntent::continuation('acme', 3, 0, self::utc('2026-10-01 11:30:00')));
      $jobs->schedule(WakeupIntent::timeout('acme', 4, 0, self::utc('2026-10-01 12:00:01')));
    });

    self::assertSame(['continue:2:0', 'timeout:1:0'], self::keys($jobs->claimDue($this->clock->now(), 2, 60)));
    self::assertSame(['continue:3:0'], self::keys($jobs->claimDue($this->clock->now(), 10, 60)));
    self::assertSame([], $jobs->claimDue($this->clock->now(), 10, 60));
    self::assertSame(['continue:2:0', 'timeout:1:0', 'continue:3:0', 'timeout:4:0'], self::keys($jobs->claimDue($this->clock->now()->modify('+61 seconds'), 10, 60)), 'expired leases are claimable again');
  }

  public function test_claim_due_refuses_to_run_inside_an_open_transaction(): void {
    $this->db->begin();
    try {
      $this->expectException(NestedTransactionRejected::class);
      $this->jobs()->claimDue($this->clock->now(), 1, 60);
    } finally {
      $this->db->rollBack();
    }
  }

  public function test_two_workers_never_claim_the_same_intent(): void {
    $jobs = $this->jobs();
    $this->inTx(function () use ($jobs) {
      for ($i = 1; $i <= 15; $i++) {
        $jobs->schedule(WakeupIntent::continuation('acme', $i, 0, self::utc('2026-10-01 11:00:00')));
      }
    });
    $other = $this->jobs($this->otherConnection());

    $seen = [];
    while (($batch = array_merge($jobs->claimDue($this->clock->now(), 2, 60), $other->claimDue($this->clock->now(), 2, 60))) !== []) {
      foreach (self::keys($batch) as $key) {
        self::assertArrayNotHasKey($key, $seen);
        $seen[$key] = true;
      }
    }
    self::assertCount(15, $seen);
  }

  public function test_complete_and_retry_later_are_fenced_by_the_claim_token(): void {
    $jobs = $this->jobs();
    $this->inTx(fn () => $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now())));
    [$a] = $jobs->claimDue($this->clock->now(), 1, 60);
    [$b] = $this->jobs($this->otherConnection())->claimDue($this->clock->now()->modify('+61 seconds'), 1, 60);

    self::assertFalse($jobs->complete($a));
    self::assertFalse($jobs->retryLater($a, 'late', $this->clock->now()));

    self::assertTrue($jobs->retryLater($b, 'LockNotAcquired', self::utc('2026-10-01 12:05:00')));
    $row = $this->row('ddd_jobs', 'idempotency_key = ?', ['timeout:1:0']);
    self::assertSame(1, (int) $row['attempts']);
    self::assertSame('LockNotAcquired', $row['last_error']);
    self::assertSame('2026-10-01 12:00:00.000000', $row['due_at'], 'a retry never moves due_at');

    self::assertSame([], $jobs->claimDue(self::utc('2026-10-01 12:04:59'), 1, 60));
    [$c] = $jobs->claimDue(self::utc('2026-10-01 12:05:00'), 1, 60);
    self::assertSame(1, $c->attempts);
    self::assertTrue($jobs->complete($c));
    self::assertSame(0, $this->countRows('ddd_jobs'));
  }

  public function test_cancel_removes_the_intent(): void {
    $jobs = $this->jobs();
    $this->inTx(function () use ($jobs) {
      $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
      $jobs->schedule(WakeupIntent::timeout('acme', 2, 0, $this->clock->now()));
    });
    $this->inTx(function () use ($jobs) {
      $jobs->cancel('timeout:1:0');
      $jobs->cancel('timeout:does-not-exist');
    });

    self::assertSame(['timeout:2:0'], self::keys($jobs->claimDue($this->clock->now(), 10, 60)));
  }

  public function test_live_intents_per_process_for_the_stranded_scan(): void {
    $jobs = $this->jobs();
    $this->inTx(function () use ($jobs) {
      $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
      $jobs->schedule(WakeupIntent::continuation('acme', 1, 1, $this->clock->now()));
    });

    self::assertTrue($jobs->hasLiveIntent(1));
    self::assertFalse($jobs->hasLiveIntent(2));
  }

  // ── ITransport (deliver jobs) ─────────────────────────────────────────────

  public function test_submit_writes_one_deliver_job_at_the_absolute_due_time(): void {
    $jobs = $this->jobs();
    self::assertInstanceOf(ITransport::class, $jobs);
    $store = $this->store();
    $store->appendFact(self::record('e1', '2026-10-01 11:00:00'), 'App\\OrderPlaced');
    [$claim] = $store->claim(1, $this->clock->now(), 60);
    $envelope = ['order_id' => 7, '__event_id' => 'e1', '__correlation_id' => 'corr-e1', '__sequence' => 3];

    $ref = $jobs->submit($claim, $envelope, self::utc('2026-10-01 13:00:00'));

    self::assertMatchesRegularExpression('/^job:[1-9][0-9]*$/', (string) $ref);
    $row = $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:e1']);
    self::assertSame('deliver', $row['kind']);
    self::assertSame('acme', $row['consumer']);
    self::assertSame('2026-10-01 13:00:00.000000', $row['due_at'], 'no relative delay is added (bug 3)');
    self::assertSame([], $jobs->claimDue(self::utc('2026-10-01 12:59:59'), 10, 60));

    [$claimed] = $jobs->claimDue(self::utc('2026-10-01 13:00:00'), 10, 60);
    self::assertSame(WakeKind::Deliver, $claimed->intent->kind);
    self::assertNull($claimed->intent->processId);
    $job = $jobs->deliveryOf($claimed);
    self::assertInstanceOf(DeliveryJob::class, $job);
    self::assertSame('e1', $job->eventId);
    self::assertSame('acme_order_placed', $job->eventType);
    self::assertSame('App\\OrderPlaced', $job->eventClass);
    self::assertSame('acme_integration_order_placed', $job->integrationAction);
    self::assertSame($envelope, $job->envelope);
  }

  public function test_delivery_of_a_wakeup_is_null(): void {
    $jobs = $this->jobs();
    $this->inTx(fn () => $jobs->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now())));
    [$claimed] = $jobs->claimDue($this->clock->now(), 1, 60);

    self::assertNull($jobs->deliveryOf($claimed));
  }

  public function test_resubmitting_a_fact_whose_job_is_still_pending_returns_the_same_reference(): void {
    $jobs = $this->jobs();
    $store = $this->store();
    $store->append(self::record('e1'));
    [$claim] = $store->claim(1, $this->clock->now(), 60);

    $first = $jobs->submit($claim, ['__event_id' => 'e1'], $this->clock->now());
    $second = $jobs->submit($claim, ['__event_id' => 'e1'], $this->clock->now());

    self::assertSame($first, $second);
    self::assertSame(1, $this->countRows('ddd_jobs'));
  }

  public function test_it_shares_the_connection_only_with_a_pdo_outbox_store_on_the_same_connection(): void {
    $jobs = $this->jobs();
    self::assertTrue($jobs->sharesConnectionWith($this->store()));
    self::assertFalse($jobs->sharesConnectionWith($this->store($this->otherConnection())));
    self::assertFalse($jobs->sharesConnectionWith(new InMemoryOutboxStore($this->clock)));
  }

  public function test_submit_and_accept_commit_or_roll_back_together(): void {
    $jobs = $this->jobs();
    $store = $this->store();
    $store->append(self::record('e1'));
    [$claim] = $store->claim(1, $this->clock->now(), 60);

    try {
      $this->inTx(function () use ($jobs, $store, $claim) {
        $ref = $jobs->submit($claim, ['__event_id' => 'e1'], $this->clock->now());
        $store->accept($claim, $ref);
        throw new \RuntimeException('crash after submit, before commit');
      });
    } catch (\RuntimeException) {
    }
    self::assertSame(0, $this->countRows('ddd_jobs'));
    self::assertSame('pending', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);

    $this->inTx(function () use ($jobs, $store, $claim) {
      self::assertTrue($store->accept($claim, $jobs->submit($claim, ['__event_id' => 'e1'], $this->clock->now())));
    });
    self::assertSame(1, $this->countRows('ddd_jobs'));
    self::assertSame('accepted', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
    self::assertSame($this->row('ddd_outbox', 'event_id = ?', ['e1'])['transport_ref'], 'job:' . $this->row('ddd_jobs', '1 = 1')['id']);
  }
}
