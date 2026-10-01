<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\PoisonFact;
use Psr\Log\NullLogger;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Defaults\Pdo\PdoDeliveryWorker;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * PdoDeliveryWorker: the delivery stage of the pdo drain over the `deliver`
 * rows of {prefix}ddd_jobs (wave3-core CR-W3C-4, W3C-R5).
 */
abstract class PdoDeliveryWorkerCases extends OutboxTestCase {

  private SubscriptionRegistry $registry;
  private PdoDeliveryLedger $ledger;
  private PdoJobStore $jobs;

  /** @var list<string> subscriber id @ order id, in call order */
  private array $calls = [];

  /** @var array<string, int> subscriber id => remaining failures */
  private array $failures = [];

  protected function setUp(): void {
    parent::setUp();
    $this->registry = new SubscriptionRegistry();
    $this->ledger = new PdoDeliveryLedger($this->db, self::PREFIX, $this->clock);
    $this->jobs = new PdoJobStore($this->db, 'acme', self::PREFIX, $this->clock);
    $this->calls = [];
    $this->failures = [];
    foreach (['listener:a' => 10, 'listener:b' => 20] as $id => $priority) {
      $this->registry->add(new Subscriber($id, $priority, OrderPlaced::class, function (IIntegrationEvent $e) use ($id): void {
        $this->calls[] = $id . '@' . $e->order_id;
        if (($this->failures[$id] ?? 0) > 0) {
          $this->failures[$id]--;
          throw new \RuntimeException("$id is down");
        }
      }));
    }
  }

  private function worker(array $eventClasses = []): PdoDeliveryWorker {
    return new PdoDeliveryWorker($this->jobs, new IntegrationDelivery($this->registry, $this->ledger, log: new NullLogger()), $eventClasses, 300, new NullLogger());
  }

  /** Append a fact, relay it by hand (claim + submit + accept), return its event id. */
  private function relayed(string $eventId, int $orderId, ?string $class = OrderPlaced::class, string $due = '2026-10-01 12:00:00'): string {
    $store = $this->store();
    $store->append_fact(self::record($eventId, $due, ['payload' => ['order_id' => $orderId, 'sku' => 'tea'], 'event_type' => OrderPlaced::name()]), $class);
    $claims = $store->claim(10, self::utc($due), 60);
    foreach ($claims as $claim) {
      (new PdoTransactionBoundary($this->db))->run(function () use ($store, $claim): void {
        $ref = $this->jobs->submit($claim, IntegrationEnvelope::wrap($claim->record->payload, $claim->record->correlation_id, 1, $claim->event_id), $claim->record->due_at);
        $store->accept($claim, $ref);
      });
    }
    return $eventId;
  }

  public function test_a_due_deliver_job_is_delivered_once_and_removed(): void {
    $this->relayed('e1', 7);

    $worker = $this->worker();
    self::assertInstanceOf(IDeliveryWorker::class, $worker);
    self::assertSame(1, $worker->run_due($this->clock->now(), 10));

    self::assertSame(['listener:a@7', 'listener:b@7'], $this->calls);
    self::assertTrue($this->ledger->delivered('listener:a', 'e1'));
    self::assertTrue($this->ledger->delivered('listener:b', 'e1'));
    self::assertSame(0, $this->countRows('ddd_jobs'));
    self::assertSame(0, $worker->run_due($this->clock->now(), 10), 'nothing left to deliver');
  }

  public function test_a_failing_subscriber_is_retried_alone_with_the_handler_backoff(): void {
    $this->relayed('e1', 7);
    $this->failures['listener:a'] = 1;

    self::assertSame(1, $this->worker()->run_due($this->clock->now(), 10));

    $job = $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:e1']);
    self::assertNotNull($job, 'the fact stays queued while a subscriber failed');
    self::assertSame(1, (int) $job['attempts']);
    self::assertSame('2026-10-01 12:00:30.000000', $job['next_attempt_at'], '30 s × 2^0 (register 5.1)');
    self::assertStringContainsString('listener:a', (string) $job['last_error']);
    self::assertNull($job['claim_token']);
    self::assertSame(['listener:a@7', 'listener:b@7'], $this->calls);

    self::assertSame(0, $this->worker()->run_due(self::utc('2026-10-01 12:00:29'), 10), 'not before the backoff');
    self::assertSame(1, $this->worker()->run_due(self::utc('2026-10-01 12:00:30'), 10));
    self::assertSame(['listener:a@7', 'listener:b@7', 'listener:a@7'], $this->calls, 'only the failed subscriber re-runs');
    self::assertSame(0, $this->countRows('ddd_jobs'));
  }

  public function test_an_exhausted_subscriber_ends_the_job_and_the_ledger_keeps_the_verdict(): void {
    $this->relayed('e1', 7);
    $this->failures['listener:a'] = 99;

    $now = $this->clock->now();
    for ($i = 0; $i < IntegrationDelivery::DEFAULT_BUDGET; $i++) {
      self::assertSame(1, $this->worker()->run_due($now, 10));
      $now = $now->modify('+2 hours');
    }

    self::assertSame(0, $this->countRows('ddd_jobs'), 'no subscriber left to retry');
    self::assertTrue($this->ledger->exhausted('listener:a', 'e1'));
    self::assertSame(5, $this->ledger->attempts('listener:a', 'e1'));
    self::assertCount(5, array_filter($this->calls, static fn (string $c) => $c === 'listener:a@7'));
  }

  public function test_it_leaves_wakeups_and_future_jobs_alone_and_honours_the_limit(): void {
    $this->relayed('e1', 1);
    $this->relayed('e2', 2);
    $this->relayed('e3', 3);
    $this->relayed('later', 4, due: '2026-10-01 13:00:00');
    (new PdoTransactionBoundary($this->db))->run(fn () => $this->jobs->schedule(WakeupIntent::timeout('acme', 5, 0, self::utc('2026-10-01 11:00:00'))));

    self::assertSame(2, $this->worker()->run_due($this->clock->now(), 2));
    self::assertSame(['listener:a@1', 'listener:b@1', 'listener:a@2', 'listener:b@2'], $this->calls);
    self::assertSame(1, $this->worker()->run_due($this->clock->now(), 10));
    self::assertSame(0, $this->worker()->run_due($this->clock->now(), 0));

    self::assertNotNull($this->row('ddd_jobs', 'idempotency_key = ?', ['timeout:5:0']), 'a wakeup is not a delivery');
    self::assertNull($this->row('ddd_jobs', 'idempotency_key = ?', ['timeout:5:0'])['claim_token']);
    self::assertNotNull($this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:later']));
  }

  public function test_a_job_without_a_recorded_class_resolves_it_from_the_event_type_map(): void {
    $this->relayed('e1', 7, class: null);

    self::assertSame(1, $this->worker([OrderPlaced::name() => OrderPlaced::class])->run_due($this->clock->now(), 10));

    self::assertSame(['listener:a@7', 'listener:b@7'], $this->calls);
    self::assertSame(0, $this->countRows('ddd_jobs'));
  }

  public function test_an_unresolvable_class_is_retried_with_the_reason_never_dropped(): void {
    $this->relayed('e1', 7, class: null);
    $this->relayed('e2', 8, class: 'App\\GoneFact');

    self::assertSame(2, $this->worker()->run_due($this->clock->now(), 10));

    self::assertSame([], $this->calls);
    foreach (['e1', 'e2'] as $id) {
      $job = $this->row('ddd_jobs', 'idempotency_key = ?', ["deliver:$id"]);
      self::assertSame(1, (int) $job['attempts']);
      self::assertStringContainsString('event class', (string) $job['last_error']);
    }
  }

  public function test_a_hydration_failure_counts_against_every_subscriber_and_retries_the_job(): void {
    $this->registry->add(new Subscriber('listener:p', 10, PoisonFact::class, static function (): void {}));
    $store = $this->store();
    $store->append_fact(self::record('poison', extra: ['payload' => ['id' => 1], 'event_type' => PoisonFact::name()]), PoisonFact::class);
    [$claim] = $store->claim(1, $this->clock->now(), 60);
    (new PdoTransactionBoundary($this->db))->run(function () use ($store, $claim): void {
      $store->accept($claim, $this->jobs->submit($claim, IntegrationEnvelope::wrap($claim->record->payload, null, 1, 'poison'), $claim->record->due_at));
    });

    self::assertSame(1, $this->worker()->run_due($this->clock->now(), 10));

    self::assertSame(1, $this->ledger->attempts('listener:p', 'poison'));
    $job = $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:poison']);
    self::assertSame(1, (int) $job['attempts']);
    self::assertStringContainsString('no longer decodes', (string) $job['last_error']);
  }
}
