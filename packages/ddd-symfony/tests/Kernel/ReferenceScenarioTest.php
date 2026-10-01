<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\AnnounceCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyCharged;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyJobFinished;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyRequested;
use TangibleDDD\Symfony\Tests\Kernel\App\Process\ToyProvision;
use TangibleDDD\Symfony\Tests\Kernel\App\Toy\ToyPaymentGateway;

/**
 * The E section 10 reference scenario as a kernel test (register section 8
 * wave 4 symfony), on Postgres 16 through the real bundle wiring: facts go
 * command → ddd_outbox → `ddd:relay --once` → `messenger:consume ddd_facts`,
 * wakeups go ddd_wakeups → `ddd:relay --once` → `messenger:consume
 * ddd_wakeups`. The bundle clock is a FrozenClock (kernel variant
 * frozen_clock), so the 30 min alarm can be reached.
 *
 * The toy saga ToyProvision: started from ToyRequested (one saga per fact,
 * also under redelivery); step `order` awaits a ToyJobFinished keyed on the
 * job id it minted, with a timeout alarm, and orders the job after the await
 * committed; step `charge` performs a D1 external effect (ChargeToyCommand
 * through EffectMiddleware and the DBAL journal) whose record() fails once,
 * so the step's retry policy re-runs it and the journal makes the re-run
 * reuse the performed charge; a failed job compensates `order`.
 *
 * The other E section 10 variants are sf conformance cells already:
 * commit failure and reaction throw (cmd.*), relay crash (relay.*), two
 * workers racing the answer and the timeout (process.timeout-vs-event),
 * worker restart (process.fresh-process-resume), the leak guard
 * (worker.no-leak), NOTIFY suppressed (PostCommitPollFallbackTest) and the
 * pooled DSN (process.start-from-web).
 */
final class ReferenceScenarioTest extends KernelTestBase {

  protected static string $variant = 'frozen_clock';

  protected function setUp(): void {
    parent::setUp();
    ToyPaymentGateway::reset();
  }

  public function test_the_bundle_wires_the_effect_middleware(): void {
    // Its position (after the act bracket, before the transaction) is what the happy path below
    // relies on: perform() runs outside any transaction, record() inside the command's own.
    self::assertInstanceOf(EffectMiddleware::class, self::getContainer()->get('test.effect_middleware'));
  }

  public function test_happy_path_keyed_answer_then_an_effect_whose_retry_reuses_the_journal(): void {
    ToyPaymentGateway::reset(failRecords: 1);

    // 1. The fact ignites the saga; its first step orders the job after the await committed.
    $requested = $this->announce(new ToyRequested('team-1'));
    $pid = $this->processId();
    self::assertSame('suspended', $this->processStatus($pid));
    $job = (string) $this->db->fetchOne("SELECT widget_id FROM app_listener_runs WHERE listener = 'toy-order'");
    self::assertSame(
      [[ToyJobFinished::class, $job]],
      array_map(static fn (array $r) => [$r['event_class'], $r['await_key']], $this->db->fetchAllAssociative('SELECT event_class, await_key FROM ddd_process_waits WHERE process_id = ?', [$pid])),
      'the keyed route is the job id the step minted (D3, D13 step_ref)'
    );
    self::assertSame(
      DeterministicCommandId::forStep('sfk', $pid, '0', 0),
      $this->db->fetchOne("SELECT cause_id FROM app_listener_runs WHERE listener = 'toy-order'"),
      'the step command id is uuid5(process, step) (D13)'
    );
    $alarm = $this->db->fetchAssociative('SELECT kind, due_at FROM ddd_wakeups WHERE process_id = ?', [$pid]);
    self::assertSame('timeout', $alarm['kind']);
    self::assertEquals($this->clock()->now()->modify('+1800 seconds'), new \DateTimeImmutable((string) $alarm['due_at']), 'absolute UTC due_at (D7)');

    // 2. A duplicate delivery of the igniting fact starts no second saga.
    $this->redeliver($requested, ToyRequested::class);
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_processes'));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'toy-order'"));

    // Worker restart mid-suspension: a new kernel (container, connection, runner, lock session).
    $this->restartWorker();
    self::assertSame('suspended', $this->processStatus($pid), 'the process row and its intent survive the restart');

    // 3. Another command announces the job's answer: resume, charge, record fails, retry reuses the charge.
    $this->announce(new ToyJobFinished($job, true));

    self::assertSame('completed', $this->processStatus($pid));
    self::assertCount(1, ToyPaymentGateway::$performed, 'perform ran once although the step ran twice');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM ddd_effect_journal WHERE invalidated_at IS NULL"));
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_outbox WHERE event_class = ?', [ToyCharged::class]), 'only the successful record() committed its fact');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'), 'the resuming save cancelled the alarm; the retry intent completed');
    self::assertSame(0, ToyPaymentGateway::$failRecords);

    // 4. A late duplicate of the timeout is stale: nothing changes.
    $c = self::getContainer();
    $c->get('tangible_ddd.transaction_boundary')->run(fn () => $c->get(IWakeupScheduler::class)->schedule(WakeupIntent::timeout('sfk', $pid, 0, $this->clock()->now())));
    $this->drain();
    self::assertSame('completed', $this->processStatus($pid));
    self::assertCount(1, ToyPaymentGateway::$performed);

    // 5. The worker left nothing behind (E section 8).
    self::assertNull(Correlation::peek());
    self::assertSame([], $c->get(EventsUnitOfWork::class)->drain());
    self::assertSame(0, $c->get('test.process_lock')->heldCount());
  }

  public function test_a_failed_job_retries_the_step_once_then_compensates_the_order(): void {
    $this->announce(new ToyRequested('team-2'));
    $pid = $this->processId();
    $job = (string) $this->db->fetchOne("SELECT widget_id FROM app_listener_runs WHERE listener = 'toy-order'");

    $this->announce(new ToyJobFinished($job, false));

    self::assertSame('failed', $this->processStatus($pid));
    self::assertSame([], ToyPaymentGateway::$performed, 'nothing was charged');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'toy-cancel' AND widget_id = 'team-2'"), 'the order was compensated once');
    self::assertStringContainsString("toy job $job failed", (string) $this->db->fetchOne("SELECT cause_id FROM app_listener_runs WHERE listener = 'toy-cancel'"));
  }

  public function test_without_an_answer_the_alarm_fails_the_saga_and_a_late_answer_is_a_no_op(): void {
    $this->announce(new ToyRequested('team-3'));
    $pid = $this->processId();
    $job = (string) $this->db->fetchOne("SELECT widget_id FROM app_listener_runs WHERE listener = 'toy-order'");

    $this->clock()->advance('+1799 seconds');
    $this->drain();
    self::assertSame('suspended', $this->processStatus($pid), 'one second early');

    $this->clock()->advance('+2 seconds');
    $this->drain();
    self::assertSame('failed', $this->processStatus($pid));
    self::assertStringContainsString('Await timed out', (string) $this->db->fetchOne('SELECT last_error FROM ddd_processes WHERE id = ?', [$pid]));

    $this->announce(new ToyJobFinished($job, true));
    self::assertSame('failed', $this->processStatus($pid), 'no resurrection after the timeout');
    self::assertSame([], ToyPaymentGateway::$performed);
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'), 'the late answer was delivered and acked (unheard)');
  }

  // ── helpers ─────────────────────────────────────────────────────────────

  /** Shut the kernel down and boot a fresh one on the same database (no schema reset). */
  private function restartWorker(): void {
    $at = $this->clock()->now();
    self::ensureKernelShutdown();
    ConsumerRegistry::reset();
    RuntimeReset::forgetRegistrationsForTests();
    Correlation::reset();
    HostDefaults::resetForTests();
    self::bootKernel(['variant' => static::$variant]);
    $this->db = self::getContainer()->get('tangible_ddd.connection');
    $this->clock()->set($at);
  }

  private function clock(): FrozenClock {
    return self::getContainer()->get('tangible_ddd.clock');
  }

  /**
   * Announce $fact through the outbox and run the workers until idle.
   *
   * @return array<string, mixed> its outbox row
   */
  private function announce(object $fact): array {
    (new AnnounceCommand($fact))->send();
    $row = $this->db->fetchAssociative('SELECT * FROM ddd_outbox WHERE event_class = ? ORDER BY id DESC LIMIT 1', [get_class($fact)]);
    self::assertIsArray($row);
    $this->drain();
    return $row;
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
      $consume = $this->console('messenger:consume', ['receivers' => [$queue], '--limit' => 1, '--time-limit' => 10]);
      self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
    }
    self::fail('the workers did not go idle');
  }

  /** @param array<string, mixed> $row */
  private function redeliver(array $row, string $class): void {
    $payload = IntegrationEnvelope::unwrap((array) json_decode((string) $row['payload'], true))->payload;
    self::getContainer()->get('messenger.transport.ddd_facts')->send(new Envelope(
      new IntegrationFactMessage('sfk', (string) $row['event_id'], (string) $row['event_type'], $class, (string) $row['integration_action'],
        IntegrationEnvelope::wrap($payload, (string) $row['correlation_id'], (int) $row['sequence'], (string) $row['event_id'])),
      [new BusNameStamp('messenger.bus.default')],
    ));
    $this->drain();
  }

  private function processId(): int {
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_processes WHERE process_class = ?', [ToyProvision::class]), 'one saga');
    return (int) $this->db->fetchOne('SELECT id FROM ddd_processes WHERE process_class = ?', [ToyProvision::class]);
  }

  private function processStatus(int $id): string {
    return (string) $this->db->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$id]);
  }
}
