<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Core\Tests\Pdo\OutboxTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Testing\RecordingSignalDispatcher;

/**
 * The core relay step (OutboxProcessor port form) over the pdo adapters:
 * PdoOutboxStore + PdoJobStore (shared connection) + PdoTransactionBoundary.
 * Not the conformance suite (that is ddd-conformance's pdo host); this pins
 * that the adapters compose with the core relay as the register says.
 */
abstract class PdoRelayCases extends OutboxTestCase {

  protected function setUp(): void {
    parent::setUp();
    HostDefaults::reset_for_tests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new RecordingSignalDispatcher());
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    parent::tearDown();
  }

  private function relay(?IHostConnection $db = null): OutboxProcessor {
    $db ??= $this->db;
    return new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(), null, null, null, $this->clock,
      $this->store($db), new PdoJobStore($db, 'acme', self::PREFIX, $this->clock), new PdoTransactionBoundary($db),
    );
  }

  public function test_the_relay_submits_and_accepts_in_one_transaction(): void {
    $this->store()->append(self::record('e1', '2026-10-01 11:00:00'));
    $this->store()->append(self::record('later', '2026-10-01 13:00:00'));

    $result = $this->relay()->process_batch();

    self::assertSame([1, 0, 0, 1], [$result->completed, $result->failed, $result->dlq, $result->total]);
    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('accepted', $row['status']);
    $job = $this->row('ddd_jobs', 'idempotency_key = ?', ['deliver:e1']);
    self::assertSame('job:' . $job['id'], $row['transport_ref']);
    self::assertSame('2026-10-01 11:00:00.000000', $job['due_at'], 'submitted at the absolute due time');
    self::assertSame('e1', json_decode($job['envelope'], true)['__event_id']);
  }

  public function test_a_crash_between_submit_and_accept_rolls_both_back_and_the_next_run_delivers_once(): void {
    $this->store()->append(self::record('e1'));
    $relay = $this->relay();
    $relay->between_submit_and_accept(static function (): void { throw new \RuntimeException('killed'); });

    try {
      $relay->process_batch();
      self::fail('expected the simulated crash');
    } catch (\RuntimeException $e) {
      self::assertSame('killed', $e->getMessage());
    }
    self::assertSame(0, $this->countRows('ddd_jobs'));
    self::assertSame('pending', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);

    $this->clock->advance('PT10M'); // past the lease
    $this->relay($this->otherConnection())->process_batch();

    self::assertSame(1, $this->countRows('ddd_jobs'));
    self::assertSame('accepted', $this->row('ddd_outbox', 'event_id = ?', ['e1'])['status']);
  }

  public function test_a_failed_job_write_is_a_rejection_retried_per_the_relay_budget(): void {
    $this->store()->append(self::record('e1'));
    $faulty = new FaultyConnection($this->db);
    $faulty->failStatement = '/INSERT INTO `tp_ddd_jobs`/';

    $result = $this->relay($faulty)->process_batch();

    self::assertSame([0, 1, 0], [$result->completed, $result->failed, $result->dlq]);
    $row = $this->row('ddd_outbox', 'event_id = ?', ['e1']);
    self::assertSame('pending', $row['status']);
    self::assertSame(1, (int) $row['attempts']);
    self::assertStringContainsString('deliver job', (string) $row['last_error']);
    self::assertSame(0, $this->countRows('ddd_jobs'));
  }
}
