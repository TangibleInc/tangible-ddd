<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\Fixtures\Process\MakeWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * The sf fixture's own guarantees, so a green scenario cannot be a vacuous
 * one: the process seams are real Postgres sessions, server errors, the
 * wakeup transport and separate php processes; a fresh schema per test that is dropped afterwards, a COMMIT failure
 * raised by Postgres itself, and the shared-connection relay hand-off.
 */
#[Group('sf')]
#[Group('conformance')]
final class SfHostFixtureTest extends TestCase {

  private function fixture(string $method): array {
    $context = new ScenarioContext(self::class, $method, null);
    $fixture = new SfHostFixture();
    $fixture->setUp($context);
    return [$fixture, $context->uniqueName('sf')];
  }

  public function test_each_test_gets_its_own_schema_and_it_is_dropped_afterwards(): void {
    [$a, $schemaA] = $this->fixture('a');
    [$b, $schemaB] = $this->fixture('b');
    $admin = PostgresDatabase::connect();
    try {
      self::assertNotSame($schemaA, $schemaB);
      $a->scenarioRows()->insert('only-in-a', 'x');
      self::assertTrue($a->scenarioRows()->has('only-in-a'));
      self::assertFalse($b->scenarioRows()->has('only-in-a'), 'schemas are isolated');
      $tables = $admin->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY 1', [$schemaA]);
      foreach (['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger', 'messenger_messages', 'conf_scenario_rows'] as $t) {
        self::assertContains($t, $tables);
      }

      $a->tearDown();
      $b->tearDown();

      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaA]), 'dropped on tearDown');
      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaB]));
    } finally {
      $a->tearDown();
      $b->tearDown();
      $admin->close();
    }
  }

  public function test_an_injected_commit_failure_is_raised_by_postgres_at_commit(): void {
    [$host] = $this->fixture('commit');
    try {
      $host->failNextCommit('injected');
      $thrown = null;
      try {
        $host->boundary()->run(static fn () => $host->scenarioRows()->insert('w', 'v'));
      } catch (TransactionFailed $e) {
        $thrown = $e;
      }

      self::assertNotNull($thrown);
      self::assertStringStartsWith('COMMIT failed', $thrown->getMessage());
      self::assertStringContainsString('23503', $thrown->getPrevious()?->getMessage() ?? '', 'a deferred FK violation reported at COMMIT');
      self::assertSame(0, $host->scenarioRows()->count());
      self::assertFalse($host->boundary()->isActive());

      $host->boundary()->run(static fn () => $host->scenarioRows()->insert('w', 'v'));
      self::assertSame(1, $host->scenarioRows()->count(), 'one-shot: the next commit succeeds');
    } finally {
      $host->tearDown();
    }
  }

  public function test_the_relay_hand_off_is_the_shared_connection_one(): void {
    [$host] = $this->fixture('shared');
    try {
      self::assertTrue($host->transport()->sharesConnectionWith($host->outbox()));
    } finally {
      $host->tearDown();
    }
  }

  public function test_the_lock_held_elsewhere_and_worker_2_are_other_postgres_sessions(): void {
    [$host, $schema] = $this->fixture('sessions');
    try {
      $key = $host->processLockKey(7);
      $host->holdProcessLockElsewhere(7);
      $holders = $this->advisoryHolders($key->postgresKey());
      self::assertCount(1, $holders);
      self::assertNotContains($this->backendPidOf($host), $holders, 'not the fixture connection');

      self::assertInstanceOf(LockNotAcquired::class, $this->thrown(fn () => $host->worker(1)->processLock()->acquire($key, 0.1)));
      $host->releaseProcessLockElsewhere(7);

      $handle = $host->worker(2)->processLock()->acquire($key, 0.1);
      self::assertNotContains($this->backendPidOf($host), $this->advisoryHolders($key->postgresKey()), 'worker 2 holds it on its own session');
      self::assertInstanceOf(LockNotAcquired::class, $this->thrown(fn () => $host->worker(1)->processLock()->acquire($key, 0.1)));
      $host->worker(2)->processLock()->release($handle);
      self::assertSame(1, $host->processLockAcquisitions(), 'one successful backend acquisition');
    } finally {
      $host->tearDown();
    }
  }

  public function test_an_injected_lock_acquire_error_is_a_server_error_and_takes_no_lock(): void {
    [$host] = $this->fixture('lock-error');
    try {
      $key = $host->processLockKey(9);
      $host->failNextProcessLockAcquire('backend down');

      $thrown = $this->thrown(fn () => $host->worker(1)->processLock()->acquire($key, 1.0));

      self::assertInstanceOf(LockNotAcquired::class, $thrown);
      self::assertStringContainsString('injected lock error: backend down', $thrown->getPrevious()?->getMessage() ?? '', 'Postgres raised it');
      self::assertSame([], $this->advisoryHolders($key->postgresKey()));
      $host->worker(1)->processLock()->release($host->worker(1)->processLock()->acquire($key, 1.0));
    } finally {
      $host->tearDown();
    }
  }

  public function test_a_lock_attempt_in_a_web_request_is_refused_as_on_a_pooled_connection(): void {
    [$host] = $this->fixture('web');
    try {
      $thrown = $this->thrown(fn () => $host->inWebRequest(fn () => $host->worker(1)->processLock()->acquire($host->processLockKey(3), 0.1)));

      self::assertInstanceOf(PooledConnectionRefused::class, $thrown);
      self::assertSame(1, $host->webLockAttempts());
      self::assertSame(0, $host->processLockAcquisitions());
    } finally {
      $host->tearDown();
    }
  }

  public function test_a_deferred_start_runs_in_a_drain_through_the_wakeup_transport(): void {
    [$host] = $this->fixture('drain');
    try {
      $process = new MakeWidgetProcess('w-1');
      $host->worker(1)->processRunner()->start($process);
      $id = (int) $process->get_id();
      self::assertSame('scheduled', $host->processRow($id)?->status);

      $report = $host->worker(1)->drainOnce();

      self::assertSame(["continue:$id:0"], $report->wakesCompleted, 'projected to ddd_wakeups and handled by ProcessWakeupHandler');
      self::assertSame('completed', $host->processRow($id)?->status);
      self::assertSame([], $host->pendingWakeups());
    } finally {
      $host->tearDown();
    }
  }

  public function test_a_fresh_process_shares_only_the_database(): void {
    [$host] = $this->fixture('fresh');
    try {
      ProcessJournal::reset();
      ProcessJournal::bind($host->scenarioRows(), $host->boundary());

      $run = $host->startInFreshProcess(new MakeWidgetProcess('w-1'));

      self::assertFalse($run->died);
      self::assertSame('completed', $host->processRow((int) $run->processId)?->status, 'the fresh process ran it in-band');
      self::assertSame([], ProcessJournal::$steps, 'nothing ran in this process');
      self::assertSame(2, $host->scenarioRows()->count(), 'its two step commands committed their rows');
    } finally {
      $host->tearDown();
      ProcessJournal::reset();
    }
  }

  private function thrown(callable $fn): ?\Throwable {
    try {
      $fn();
    } catch (\Throwable $e) {
      return $e;
    }
    return null;
  }

  private function backendPidOf(SfHostFixture $host): int {
    return (int) $host->connection()->fetchOne('SELECT pg_backend_pid()');
  }

  /** @return list<int> backend pids holding the session advisory lock on $key */
  private function advisoryHolders(int $key): array {
    $admin = PostgresDatabase::connect();
    try {
      return array_map('intval', $admin->fetchFirstColumn(
        "SELECT pid FROM pg_locks WHERE locktype = 'advisory' AND granted
           AND ((classid::bigint << 32) | objid::bigint) = ?",
        [$key],
      ));
    } finally {
      $admin->close();
    }
  }
}
