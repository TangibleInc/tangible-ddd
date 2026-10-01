<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;

/**
 * Kernel tests with a real `ddd:relay` loop in a separate php process
 * (tests/Kernel/bin/relay-loop.php, the same kernel variant): the test
 * process commits, the worker relays, and the test measures how long the
 * hand-off took by watching the outbox row. Used by the D14 tests
 * (scenario wakeup.post-commit).
 */
abstract class RelayWorkerTestBase extends KernelTestBase {

  /** ddd:relay --sleep: the poll interval when a step found nothing. */
  protected const POLL_SECONDS = 3;

  /** @var resource|null */
  private $worker = null;
  /** @var array<int, resource> */
  private array $pipes = [];

  protected function tearDown(): void {
    $this->stopWorker();
    parent::tearDown();
  }

  protected function startWorker(): void {
    $script = \dirname(__DIR__) . '/Kernel/bin/relay-loop.php';
    $this->worker = proc_open(
      [PHP_BINARY, $script, static::$variant, '--sleep=' . self::POLL_SECONDS, '--time-limit=60'],
      [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
      $this->pipes,
      null,
      ['DDD_SF_PG_URL' => (string) getenv('DDD_SF_PG_URL'), 'APP_ENV' => 'test', 'SHELL_VERBOSITY' => '-1'] + getenv(),
    );
    self::assertIsResource($this->worker, 'the relay worker started');
    stream_set_blocking($this->pipes[2], false);
  }

  protected function stopWorker(): void {
    if ($this->worker === null) {
      return;
    }
    proc_terminate($this->worker);
    foreach ($this->pipes as $p) {
      if (is_resource($p)) {
        fclose($p);
      }
    }
    proc_close($this->worker);
    $this->worker = null;
    $this->pipes = [];
  }

  protected function workerErrors(): string {
    return isset($this->pipes[2]) && is_resource($this->pipes[2]) ? (string) stream_get_contents($this->pipes[2]) : '';
  }

  /** Commit one fact (a widget registration) and return its event id. */
  protected function commitFact(string $widgetId): string {
    (new RegisterWidgetCommand($widgetId, 'w'))->send();
    $id = $this->db->fetchOne("SELECT event_id FROM ddd_outbox WHERE payload LIKE ? ORDER BY id DESC LIMIT 1", ['%"' . $widgetId . '"%']);
    self::assertIsString($id, "the fact for $widgetId is in the outbox");
    return $id;
  }

  /** Seconds until the worker accepted the outbox row (null = not within $max). */
  protected function secondsUntilAccepted(string $eventId, float $max): ?float {
    $start = microtime(true);
    while (($elapsed = microtime(true) - $start) < $max) {
      if ($this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$eventId]) === 'accepted') {
        return $elapsed;
      }
      usleep(20_000);
    }
    return null;
  }

  /** Wait until the worker has relayed a primer fact: it is then in its idle wait. */
  protected function primeWorker(): void {
    $primer = $this->commitFact('primer');
    self::assertNotNull($this->secondsUntilAccepted($primer, 20.0), 'the worker relays at all: ' . $this->workerErrors());
  }

  /** Wait until the worker's session is idle in LISTEN (its last statement). */
  protected function waitUntilWorkerListens(): void {
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
      $n = (int) $this->db->fetchOne(
        "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND query ILIKE 'LISTEN %' AND state = 'idle'"
      );
      if ($n > 0) {
        return;
      }
      usleep(20_000);
    }
    self::fail('the relay worker never went idle in LISTEN: ' . $this->workerErrors());
  }
}
