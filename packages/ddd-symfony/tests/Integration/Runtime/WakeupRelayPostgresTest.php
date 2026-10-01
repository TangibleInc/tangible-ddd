<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Runtime;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Application\Process\ProcessLockUnavailable;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Runtime\Wakeup\IProcessWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelay;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\OrderProcess;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;

/**
 * Register 5.3 on Postgres 16: intent rows are the source of truth and the
 * `ddd_wakeups` Messenger transport is a projection. The relay leases due
 * intents and sends one message per lease; the handler wakes the process and
 * completes the intent, or retries / exhausts it. A lost message is
 * re-projected once the lease expires (process.intent-survives-queue-failure);
 * the stranded scan re-mints a Continue intent for a `scheduled` row that
 * has none (5.3 step 5).
 */
final class WakeupRelayPostgresTest extends PostgresTestCase {

  private FrozenClock $clock;
  private DbalWakeupScheduler $scheduler;
  private DbalProcessStore $store;
  private DbalTransactionBoundary $boundary;
  private InMemoryTransport $transport;
  private RecordingLogger $log;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->scheduler = new DbalWakeupScheduler($this->db);
    $this->store = new DbalProcessStore($this->db, $this->clock);
    $this->boundary = new DbalTransactionBoundary($this->db, NestedPolicy::Reject);
    $this->transport = new InMemoryTransport();
    $this->log = new RecordingLogger();
  }

  private function relay(?SenderInterface $sender = null, int $lease = 300): WakeupRelay {
    return new WakeupRelay($this->scheduler, $this->store, $this->boundary, $sender ?? $this->transport, $this->clock, 'acme', $lease, 60, $this->log);
  }

  private function schedule(WakeupIntent $i): void {
    $this->boundary->run(fn () => $this->scheduler->schedule($i));
  }

  /** @return list<ProcessWakeupMessage> */
  private function sent(): array {
    return array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport->getSent());
  }

  private function handler(IProcessWakeTarget $target): ProcessWakeupHandler {
    return new ProcessWakeupHandler($target, $this->scheduler, $this->clock, $this->log);
  }

  public function test_a_due_intent_is_projected_once_per_lease_with_its_claim(): void {
    $this->schedule(WakeupIntent::timeout('acme', 5, 2, $this->clock->now()));
    $this->schedule(WakeupIntent::continuation('acme', 6, 0, $this->clock->now()->modify('+1 hour')));

    $report = $this->relay()->runOnce(10);

    self::assertSame(['timeout:5:2'], $report->projected);
    [$m] = $this->sent();
    self::assertInstanceOf(ProcessWakeupMessage::class, $m);
    self::assertSame('timeout', $m->kind);
    self::assertSame(5, $m->processId);
    self::assertSame(2, $m->stepIndex);
    self::assertSame('suspended', $m->expectedStatus);
    self::assertSame('timeout:5:2', $m->idempotencyKey);
    self::assertNotSame('', $m->claimToken);

    self::assertSame([], $this->relay()->runOnce(10)->projected, 'leased: not projected again');
    self::assertCount(1, $this->transport->getSent());
  }

  public function test_a_lost_message_is_re_projected_after_the_lease_expires(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $this->relay(null, 30)->runOnce(10);
    $this->transport->reset(); // the queue lost it (or the worker died before handling)

    $this->clock->advance('+31 seconds');
    self::assertSame(['continue:5:0'], $this->relay(null, 30)->runOnce(10)->projected);
    self::assertCount(1, $this->transport->getSent());
  }

  public function test_a_send_failure_retries_the_intent_later(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $broken = new class implements SenderInterface {
      public function send(Envelope $envelope): Envelope { throw new \RuntimeException('transport down'); }
    };

    $report = $this->relay($broken)->runOnce(10);

    self::assertSame(['continue:5:0'], $report->failed);
    $row = $this->db->fetchAssociative('SELECT attempts, last_error, claim_token FROM ddd_wakeups');
    self::assertSame(1, (int) $row['attempts']);
    self::assertStringContainsString('transport down', (string) $row['last_error']);
    self::assertNull($row['claim_token']);
    self::assertSame([], $this->relay()->runOnce(10)->projected, 'backed off');
    $this->clock->advance('+2 seconds');
    self::assertSame(['continue:5:0'], $this->relay()->runOnce(10)->projected);
  }

  public function test_the_handler_wakes_the_process_and_completes_the_intent(): void {
    $this->schedule(WakeupIntent::timeout('acme', 5, 2, $this->clock->now()));
    $this->relay()->runOnce(10);
    $target = new RecordingWakeTarget();

    $outcome = ($this->handler($target))($this->sent()[0]);

    self::assertSame(ProcessWakeupHandler::COMPLETED, $outcome, 'the handler result names what happened (HandledStamp)');
    self::assertCount(1, $target->woken);
    self::assertSame(WakeKind::Timeout, $target->woken[0]->kind);
    self::assertSame(5, $target->woken[0]->processId);
    self::assertSame(2, $target->woken[0]->stepIndex);
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_wakeups'));
  }

  public function test_a_lock_or_fence_failure_retries_with_backoff(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $this->relay()->runOnce(10);
    $target = new RecordingWakeTarget([new ProcessLockUnavailable('lock busy', 0, new LockNotAcquired('busy'))]);

    self::assertSame(ProcessWakeupHandler::RETRIED, ($this->handler($target))($this->sent()[0]));

    $row = $this->db->fetchAssociative('SELECT attempts, next_attempt_at, exhausted_at FROM ddd_wakeups');
    self::assertSame(1, (int) $row['attempts']);
    self::assertNull($row['exhausted_at']);
    self::assertSame([], $this->relay()->runOnce(10)->projected);
    $this->clock->advance('+2 seconds');
    self::assertSame(['continue:5:0'], $this->relay()->runOnce(10)->projected, 'retried after 2 s (5.1: 2 s x 2^n)');

    ($this->handler(new RecordingWakeTarget([new ConcurrentProcessModification('moved on')])))($this->sent()[1]);
    self::assertSame(2, (int) $this->db->fetchOne('SELECT attempts FROM ddd_wakeups'));
    $this->clock->advance('+3 seconds');
    self::assertSame([], $this->relay()->runOnce(10)->projected, 'second retry waits 4 s');
    $this->clock->advance('+1 second');
    self::assertSame(['continue:5:0'], $this->relay()->runOnce(10)->projected);
  }

  public function test_the_tenth_failed_attempt_exhausts_the_intent(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $this->db->executeStatement('UPDATE ddd_wakeups SET attempts = 9');
    $this->relay()->runOnce(10);

    self::assertSame(ProcessWakeupHandler::EXHAUSTED, ($this->handler(new RecordingWakeTarget([new LockNotAcquired('busy')])))($this->sent()[0]));

    $row = $this->db->fetchAssociative('SELECT attempts, exhausted_at FROM ddd_wakeups');
    self::assertSame(10, (int) $row['attempts']);
    self::assertNotNull($row['exhausted_at']);
    self::assertNotEmpty($this->log->at('error'));
  }

  public function test_a_non_retryable_wake_failure_exhausts_the_intent_for_the_operator(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $this->relay()->runOnce(10);

    ($this->handler(new RecordingWakeTarget([new \LogicException('wiring bug')])))($this->sent()[0]);

    self::assertNotNull($this->db->fetchOne('SELECT exhausted_at FROM ddd_wakeups'));
    self::assertStringContainsString('wiring bug', implode("\n", $this->log->at('error')));
  }

  public function test_a_handler_whose_lease_was_lost_does_not_touch_the_new_holder(): void {
    $this->schedule(WakeupIntent::continuation('acme', 5, 0, $this->clock->now()));
    $this->relay(null, 30)->runOnce(10);
    $this->clock->advance('+31 seconds');
    $this->relay(null, 30)->runOnce(10);
    [$stale, $fresh] = $this->sent();

    self::assertSame(ProcessWakeupHandler::LEASE_LOST, ($this->handler(new RecordingWakeTarget()))($stale));
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_wakeups'), 'the stale message cannot complete the re-leased intent');

    ($this->handler(new RecordingWakeTarget()))($fresh);
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_wakeups'));
  }

  public function test_the_stranded_scan_mints_a_continue_intent_for_a_scheduled_row_and_reports_running_ones(): void {
    $scheduled = OrderProcess::started(1);
    $scheduled->advance(status: 'scheduled');
    $this->store->insert($scheduled);
    $running = OrderProcess::started(2);
    $this->store->insert($running);
    $this->clock->advance('+16 minutes');

    $report = $this->relay()->runOnce(10);

    self::assertSame([$scheduled->get_id()], $report->strandedRequeued);
    self::assertSame([$running->get_id()], $report->strandedReported);
    self::assertSame(['continue:' . $scheduled->get_id() . ':0'], $report->projected, 'the minted intent is projected in the same tick');
    self::assertNotEmpty($this->log->at('warning'));

    // The scan is throttled (every 60 s), and a requeued row now has a live intent.
    $this->clock->advance('+61 seconds');
    self::assertSame([], $this->relay()->runOnce(10)->strandedRequeued);
  }

  public function test_the_stranded_scan_runs_at_most_once_per_interval(): void {
    $relay = $this->relay();
    $relay->runOnce(10);

    $p = OrderProcess::started(1);
    $p->advance(status: 'scheduled');
    $this->store->insert($p);
    $this->clock->advance('+16 minutes');

    self::assertSame([$p->get_id()], $relay->runOnce(10)->strandedRequeued);
    $this->db->executeStatement('DELETE FROM ddd_wakeups');
    $this->clock->advance('+30 seconds');
    self::assertSame([], $relay->runOnce(10)->strandedRequeued, 'not yet: last scan 30 s ago');
  }
}

final class RecordingWakeTarget implements IProcessWakeTarget {

  /** @var list<WakeupIntent> */
  public array $woken = [];

  /** @param list<\Throwable> $failures thrown by the first wakes, in order */
  public function __construct(private array $failures = []) {}

  public function wake(WakeupIntent $intent): void {
    $this->woken[] = $intent;
    if ($this->failures !== []) {
      throw array_shift($this->failures);
    }
  }
}
