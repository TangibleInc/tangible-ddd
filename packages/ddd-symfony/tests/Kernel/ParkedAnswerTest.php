<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Persistence\DbalParkingScheduler;
use TangibleDDD\Symfony\Persistence\ParkedFacts;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\AnnounceCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyJobFinished;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyRequested;
use TangibleDDD\Symfony\Tests\Kernel\App\Process\ToyProvision;
use TangibleDDD\Symfony\Tests\Kernel\App\Toy\ToyPaymentGateway;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * AW2 on the bundle wiring (wave 5, CR-W5CC-7): the answer to a suspended
 * process arrives while another session holds the process lock. The resume
 * is parked as a ResumeRetry intent carrying the fact (ddd_wakeups.fact),
 * the fact's delivery completes (no ledger attempt, never dead-lettered),
 * and the intent, projected through the ddd_wakeups transport like any
 * other, resumes the process once the lock is free.
 *
 * This is the inversion of TXP's ProcessLockContentionTest "an answer held
 * off longer than the delivery budget is dead-lettered".
 */
final class ParkedAnswerTest extends KernelTestBase {

  protected static string $variant = 'frozen_clock';

  protected function setUp(): void {
    parent::setUp();
    ToyPaymentGateway::reset();
  }

  public function test_every_consumer_scheduler_carries_facts(): void {
    $c = self::getContainer();

    self::assertInstanceOf(DbalParkingScheduler::class, $c->get(IWakeupScheduler::class));
    self::assertInstanceOf(ICarriesFacts::class, $c->get(IWakeupScheduler::class));
    self::assertInstanceOf(ParkedFacts::class, $c->get('test.process_wake_target'));
  }

  public function test_an_answer_held_off_by_the_lock_is_parked_and_resumes_the_process_later(): void {
    $this->announce(new ToyRequested('team-p'));
    $pid = (int) $this->db->fetchOne('SELECT id FROM ddd_processes WHERE process_class = ?', [ToyProvision::class]);
    self::assertSame('suspended', $this->process_status($pid));
    $job = (string) $this->db->fetchOne("SELECT widget_id FROM app_listener_runs WHERE listener = 'toy-order'");

    $other = PostgresDatabase::connect();
    $holder = new PostgresAdvisoryProcessLock($other);
    $held = $holder->acquire(new LockKey('sfk', '', $pid), 0.0);
    try {
      // The answer: its resume cannot take the lock and is parked.
      $this->announce(new ToyJobFinished($job, true));

      self::assertSame('suspended', $this->process_status($pid), 'nothing ran unlocked');
      $parked = $this->db->fetchAssociative("SELECT idempotency_key, expected_status, step_index, fact FROM ddd_wakeups WHERE kind = 'resume_retry' AND process_id = ?", [$pid]);
      self::assertIsArray($parked, 'a ResumeRetry intent carries the answer');
      self::assertStringContainsString(':fact-', (string) $parked['idempotency_key']);
      self::assertSame('suspended', $parked['expected_status']);
      $fact = json_decode((string) $parked['fact'], true);
      self::assertSame(ToyJobFinished::class, $fact['class']);
      self::assertSame($job, $fact['payload']['job_id'] ?? null);
      self::assertSame(0, $this->countRows("SELECT count(*) FROM ddd_delivery_ledger WHERE subscriber_id LIKE '%resume:%' AND (attempts > 0 OR exhausted_at IS NOT NULL)"), 'the answer spent no delivery attempt');
      self::assertSame(0, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"), 'the fact was delivered and acked');

      // Its wake is due after the backoff; the lock is still taken, so it is retried, not dropped.
      $this->clock()->advance('+3 seconds');
      $this->drain();
      self::assertSame('suspended', $this->process_status($pid));
      $retried = $this->db->fetchAssociative('SELECT attempts, last_error, exhausted_at FROM ddd_wakeups WHERE idempotency_key = ?', [$parked['idempotency_key']]);
      self::assertSame(1, (int) $retried['attempts']);
      self::assertNull($retried['exhausted_at']);
      self::assertSame(1, $this->countRows("SELECT count(*) FROM ddd_wakeups WHERE kind = 'timeout' AND process_id = ?", [$pid]), 'the await timeout is untouched: the parked wake is not a timeout');
    } finally {
      $holder->release($held);
      $other->close();
    }

    $this->clock()->advance('+10 seconds');
    $this->drain();

    self::assertSame('completed', $this->process_status($pid), 'the parked answer resumed the process');
    self::assertCount(1, ToyPaymentGateway::$performed, 'the charge step ran with the answer');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'), 'the parked intent completed and the alarm was cancelled');
  }

  /**
   * HC5-1 (register 5.1, WakeRetryPolicy): a parked answer held off past the
   * wake budget is exhausted for the operator but still retried at the cap,
   * so the process resumes once the lock is free; a wake is never dropped.
   */
  public function test_a_parked_answer_held_off_past_the_budget_is_kept_retrying_at_the_cap(): void {
    $this->announce(new ToyRequested('team-x'));
    $pid = (int) $this->db->fetchOne('SELECT id FROM ddd_processes WHERE process_class = ?', [ToyProvision::class]);
    $job = (string) $this->db->fetchOne("SELECT widget_id FROM app_listener_runs WHERE listener = 'toy-order'");

    $other = PostgresDatabase::connect();
    $holder = new PostgresAdvisoryProcessLock($other);
    $held = $holder->acquire(new LockKey('sfk', '', $pid), 0.0);
    try {
      $this->announce(new ToyJobFinished($job, true));
      $key = (string) $this->db->fetchOne("SELECT idempotency_key FROM ddd_wakeups WHERE kind = 'resume_retry' AND process_id = ?", [$pid]);
      self::assertNotSame('', $key, 'the answer is parked');

      $budget = ProcessWakeupHandler::BUDGET;
      for ($n = 1; $n <= $budget + 2; $n++) {
        $this->clock()->advance('+' . (ProcessWakeupHandler::MAX_DELAY_SECONDS + 1) . ' seconds');
        $this->drain();
        $row = $this->db->fetchAssociative('SELECT attempts, exhausted_at, next_attempt_at FROM ddd_wakeups WHERE idempotency_key = ?', [$key]);
        self::assertIsArray($row, "drain $n: the parked intent is kept");
        self::assertSame($n, (int) $row['attempts'], "drain $n: the intent was claimed and retried");
        self::assertSame('suspended', $this->process_status($pid));
        if ($n < $budget) {
          self::assertNull($row['exhausted_at'], "drain $n: within the budget");
        } else {
          self::assertNotNull($row['exhausted_at'], "drain $n: the budget is spent, the operator sees it");
          self::assertNotNull($row['next_attempt_at'], "drain $n: still retried at the cap");
        }
      }

      /** @var IOperatorView $view */
      $view = self::getContainer()->get('test.operator_view');
      $wakes = array_values(array_filter($view->list(Layer::Wakeup), static fn ($i) => $i->key === $key));
      self::assertCount(1, $wakes, 'the operator view shows the exhausted wake while the lock is held');
      self::assertSame($budget + 2, $wakes[0]->attempts);
      self::assertSame($budget, $wakes[0]->budget);
      self::assertSame(['rearm'], $wakes[0]->repairs);
      self::assertStringContainsString('Lock', (string) $wakes[0]->last_error);
    } finally {
      $holder->release($held);
      $other->close();
    }

    $this->clock()->advance('+' . (ProcessWakeupHandler::MAX_DELAY_SECONDS + 1) . ' seconds');
    $this->drain();

    self::assertSame('completed', $this->process_status($pid), 'the parked answer resumed the process at the cap');
    self::assertCount(1, ToyPaymentGateway::$performed, 'the charge step ran once');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'), 'the intent completed');
    self::assertSame([], self::getContainer()->get('test.operator_view')->list(Layer::Wakeup), 'nothing left for the operator');
  }

  public function test_a_parked_intent_whose_fact_was_lost_is_never_woken_as_a_timeout(): void {
    $this->announce(new ToyRequested('team-q'));
    $pid = (int) $this->db->fetchOne('SELECT id FROM ddd_processes WHERE process_class = ?', [ToyProvision::class]);
    $c = self::getContainer();
    // A parked-fact key without its fact (a row repaired by hand, or written by a host without schema 011).
    $c->get('tangible_ddd.transaction_boundary')->run(static fn () => $c->get(IWakeupScheduler::class)->schedule(
      WakeupIntent::resume_retry('sfk', $pid, 0, 'suspended', 0, $c->get('tangible_ddd.clock')->now(), 'fact-0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d51')
    ));

    $this->drain();

    self::assertSame('suspended', $this->process_status($pid), 'not timed out');
    self::assertSame(0, $this->countRows("SELECT count(*) FROM ddd_wakeups WHERE kind = 'resume_retry'"), 'the intent completed as a no-op');
  }

  // ── helpers ─────────────────────────────────────────────────────────────

  private function clock(): FrozenClock {
    return self::getContainer()->get('tangible_ddd.clock');
  }

  private function process_status(int $id): string {
    return (string) $this->db->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$id]);
  }

  private function announce(object $fact): void {
    (new AnnounceCommand($fact))->send();
    $this->drain();
  }

  /** ddd:relay --once, then one message from whichever queue has a due one, until nothing moves. */
  private function drain(): void {
    for ($i = 0; $i < 30; $i++) {
      $this->console('ddd:relay', ['--once' => true]);
      $queue = $this->db->fetchOne(
        'SELECT queue_name FROM messenger_messages WHERE delivered_at IS NULL AND available_at <= now() ORDER BY id LIMIT 1'
      );
      if ($queue === false) {
        return;
      }
      $consume = $this->console('messenger:consume', ['receivers' => [$queue], '--limit' => 1, '--time-limit' => 20]);
      self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
    }
    self::fail('the workers did not go idle');
  }
}
