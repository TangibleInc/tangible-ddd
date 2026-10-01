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

  /** @return array{SfHostFixture, string} the set-up fixture and its schema name */
  private function fixture(string $method): array {
    $context = new ScenarioContext(self::class, $method, null);
    $fixture = new SfHostFixture();
    $fixture->set_up($context);
    return [$fixture, $context->unique_name('sf')];
  }

  public function test_each_test_gets_its_own_schema_and_it_is_dropped_afterwards(): void {
    [$a, $schemaA] = $this->fixture('a');
    [$b, $schemaB] = $this->fixture('b');
    $admin = PostgresDatabase::connect();
    try {
      self::assertNotSame($schemaA, $schemaB);
      $a->rows()->insert('only-in-a', 'x');
      self::assertTrue($a->rows()->has('only-in-a'));
      self::assertFalse($b->rows()->has('only-in-a'), 'schemas are isolated');
      $tables = $admin->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY 1', [$schemaA]);
      foreach (['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger', 'messenger_messages', 'conf_scenario_rows'] as $t) {
        self::assertContains($t, $tables);
      }

      $a->tear_down();
      $b->tear_down();

      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaA]), 'dropped on tearDown');
      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaB]));
    } finally {
      $a->tear_down();
      $b->tear_down();
      $admin->close();
    }
  }

  public function test_an_injected_commit_failure_is_raised_by_postgres_at_commit(): void {
    [$host] = $this->fixture('commit');
    try {
      $host->fail_next_commit('injected');
      $thrown = null;
      try {
        $host->boundary()->run(static fn () => $host->rows()->insert('w', 'v'));
      } catch (TransactionFailed $e) {
        $thrown = $e;
      }

      self::assertNotNull($thrown);
      self::assertStringStartsWith('COMMIT failed', $thrown->getMessage());
      self::assertStringContainsString('23503', $thrown->getPrevious()?->getMessage() ?? '', 'a deferred FK violation reported at COMMIT');
      self::assertSame(0, $host->rows()->count());
      self::assertFalse($host->boundary()->is_active());

      $host->boundary()->run(static fn () => $host->rows()->insert('w', 'v'));
      self::assertSame(1, $host->rows()->count(), 'one-shot: the next commit succeeds');
    } finally {
      $host->tear_down();
    }
  }

  public function test_the_relay_hand_off_is_the_shared_connection_one(): void {
    [$host] = $this->fixture('shared');
    try {
      self::assertTrue($host->transport()->shares_connection($host->outbox()));
    } finally {
      $host->tear_down();
    }
  }

  public function test_the_lock_held_elsewhere_and_worker_2_are_other_postgres_sessions(): void {
    [$host, $schema] = $this->fixture('sessions');
    try {
      $key = $host->lock_key(7);
      $host->hold_lock_elsewhere(7);
      $holders = $this->advisoryHolders($key->postgres_key());
      self::assertCount(1, $holders);
      self::assertNotContains($this->backendPidOf($host), $holders, 'not the fixture connection');

      self::assertInstanceOf(LockNotAcquired::class, $this->thrown(fn () => $host->worker(1)->lock()->acquire($key, 0.1)));
      $host->release_lock_elsewhere(7);

      $handle = $host->worker(2)->lock()->acquire($key, 0.1);
      self::assertNotContains($this->backendPidOf($host), $this->advisoryHolders($key->postgres_key()), 'worker 2 holds it on its own session');
      self::assertInstanceOf(LockNotAcquired::class, $this->thrown(fn () => $host->worker(1)->lock()->acquire($key, 0.1)));
      $host->worker(2)->lock()->release($handle);
      self::assertSame(1, $host->lock_acquisitions(), 'one successful backend acquisition');
    } finally {
      $host->tear_down();
    }
  }

  public function test_an_injected_lock_acquire_error_is_a_server_error_and_takes_no_lock(): void {
    [$host] = $this->fixture('lock-error');
    try {
      $key = $host->lock_key(9);
      $host->fail_next_lock('backend down');

      $thrown = $this->thrown(fn () => $host->worker(1)->lock()->acquire($key, 1.0));

      self::assertInstanceOf(LockNotAcquired::class, $thrown);
      self::assertStringContainsString('injected lock error: backend down', $thrown->getPrevious()?->getMessage() ?? '', 'Postgres raised it');
      self::assertSame([], $this->advisoryHolders($key->postgres_key()));
      $host->worker(1)->lock()->release($host->worker(1)->lock()->acquire($key, 1.0));
    } finally {
      $host->tear_down();
    }
  }

  public function test_a_lock_attempt_in_a_web_request_is_refused_as_on_a_pooled_connection(): void {
    [$host] = $this->fixture('web');
    try {
      $thrown = $this->thrown(fn () => $host->in_web_request(fn () => $host->worker(1)->lock()->acquire($host->lock_key(3), 0.1)));

      self::assertInstanceOf(PooledConnectionRefused::class, $thrown);
      self::assertSame(1, $host->webLockAttempts());
      self::assertSame(0, $host->lock_acquisitions());
    } finally {
      $host->tear_down();
    }
  }

  public function test_a_deferred_start_runs_in_a_drain_through_the_wakeup_transport(): void {
    [$host] = $this->fixture('drain');
    try {
      $process = new MakeWidgetProcess('w-1');
      $host->worker(1)->runner()->start($process);
      $id = (int) $process->get_id();
      self::assertSame('scheduled', $host->process_row($id)?->status);

      $report = $host->worker(1)->drain_once();

      self::assertSame(["continue:$id:0"], $report->wakes_completed, 'projected to ddd_wakeups and handled by ProcessWakeupHandler');
      self::assertSame('completed', $host->process_row($id)?->status);
      self::assertSame([], $host->live_intents());
    } finally {
      $host->tear_down();
    }
  }

  public function test_a_fresh_process_shares_only_the_database(): void {
    [$host] = $this->fixture('fresh');
    try {
      ProcessJournal::reset();
      ProcessJournal::bind($host->rows(), $host->boundary());

      $run = $host->start_fresh(new MakeWidgetProcess('w-1'));

      self::assertFalse($run->died);
      self::assertSame('completed', $host->process_row((int) $run->process_id)?->status, 'the fresh process ran it in-band');
      self::assertSame([], ProcessJournal::$steps, 'nothing ran in this process');
      self::assertSame(2, $host->rows()->count(), 'its two step commands committed their rows');
    } finally {
      $host->tear_down();
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
