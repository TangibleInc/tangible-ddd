<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;

/**
 * Register 5.2 on Postgres 16: a session-scoped pg_try_advisory_lock polled
 * to a deadline, key (crc32(prefix) << 32) | id, released in finally. Every
 * contention test uses two independent DBAL connections (two sessions).
 */
final class PostgresAdvisoryProcessLockTest extends PostgresTestCase {

  public function test_a_second_connection_cannot_take_a_held_lock_and_times_out(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());
    $key = new LockKey('acme', '', 42);

    $a->acquire($key, 1.0);

    $started = microtime(true);
    try {
      $b->acquire($key, 0.4);
      self::fail('the second session entered a held lock');
    } catch (LockNotAcquired $e) {
      self::assertStringContainsString('timed out', $e->getMessage());
    }
    $waited = microtime(true) - $started;
    self::assertGreaterThanOrEqual(0.4, $waited, 'polled until the deadline');
    self::assertLessThan(1.5, $waited, 'gave up shortly after the deadline');
    self::assertSame(0, $b->held_count());
  }

  public function test_release_lets_the_other_connection_acquire(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());
    $key = new LockKey('acme', '', 7);

    $handle = $a->acquire($key, 1.0);
    self::assertSame(1, $a->held_count());
    $a->release($handle);
    self::assertSame(0, $a->held_count());

    $b->acquire($key, 0.2);
    self::assertSame(1, $b->held_count());
  }

  public function test_a_released_in_finally_lock_is_free_even_when_the_work_throws(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());
    $key = new LockKey('acme', '', 8);

    try {
      $h = $a->acquire($key, 1.0);
      try {
        throw new \RuntimeException('step failed');
      } finally {
        $a->release($h);
      }
    } catch (\RuntimeException) {
    }

    $b->acquire($key, 0.2);
    self::assertSame(1, $b->held_count());
  }

  public function test_contention_later_succeeds_when_the_holder_goes_away_before_the_deadline(): void {
    $key = new LockKey('acme', '', 9);
    [$proc, $pipes] = $this->holdInChildProcess($key, 600);

    try {
      $lock = new PostgresAdvisoryProcessLock($this->db);
      $started = microtime(true);
      $lock->acquire($key, 5.0);
      $waited = microtime(true) - $started;

      self::assertGreaterThan(0.3, $waited, 'it waited for the other session');
      self::assertLessThan(4.0, $waited);
      self::assertSame(1, $lock->held_count());
    } finally {
      foreach ($pipes as $p) {
        fclose($p);
      }
      proc_close($proc);
    }
  }

  public function test_the_key_is_crc32_of_the_prefix_shifted_left_32_or_the_process_id(): void {
    $lock = new PostgresAdvisoryProcessLock($this->db);
    $key = new LockKey('acme', '', 123456);

    $lock->acquire($key, 1.0);

    $expected = (crc32('acme') << 32) | 123456;
    $row = $this->db->fetchAssociative(
      "SELECT classid::bigint AS hi, objid::bigint AS lo FROM pg_locks
        WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objsubid = 1"
    );
    self::assertNotFalse($row, 'a session advisory lock is held');
    self::assertSame($expected, ((int) $row['hi'] << 32) | (int) $row['lo']);
    self::assertSame($expected, $key->postgres_key());
  }

  public function test_the_lock_is_session_scoped_and_survives_a_commit(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());
    $key = new LockKey('acme', '', 10);

    $this->db->beginTransaction();
    $a->acquire($key, 1.0);
    $this->db->commit();

    $this->expectException(LockNotAcquired::class);
    $b->acquire($key, 0.2);
  }

  public function test_two_consumers_with_the_same_process_id_do_not_contend(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());

    $a->acquire(new LockKey('acme', '', 5), 1.0);
    $b->acquire(new LockKey('globex', '', 5), 0.2);

    self::assertSame(1, $a->held_count());
    self::assertSame(1, $b->held_count());
  }

  public function test_a_query_error_is_lock_not_acquired_and_nothing_is_held(): void {
    $lock = new PostgresAdvisoryProcessLock($this->db);
    $this->db->beginTransaction();
    try {
      $this->db->executeStatement('SELECT * FROM no_such_table_for_lock_test');
    } catch (\Throwable) {
      // the transaction is now aborted (25P02 for every further statement)
    }

    try {
      $lock->acquire(new LockKey('acme', '', 11), 1.0);
      self::fail('an errored acquire entered the section');
    } catch (LockNotAcquired $e) {
      self::assertNotNull($e->getPrevious(), 'the driver error is attached');
    }
    self::assertSame(0, $lock->held_count());
    $this->db->rollBack();
  }

  public function test_force_release_all_drops_every_lock_this_instance_holds(): void {
    $a = new PostgresAdvisoryProcessLock($this->db);
    $b = new PostgresAdvisoryProcessLock($this->secondConnection());
    $stale = $a->acquire(new LockKey('acme', '', 1), 1.0);
    $a->acquire(new LockKey('acme', '', 2), 1.0);

    self::assertSame(2, $a->release_all());
    self::assertSame(0, $a->held_count());
    self::assertSame(0, $a->release_all());

    $b->acquire(new LockKey('acme', '', 1), 0.2);
    $b->acquire(new LockKey('acme', '', 2), 0.2);

    $a->release($stale); // stale handle: ignored, never throws
    self::assertSame(0, $a->held_count());
  }

  public function test_release_of_an_unknown_handle_never_throws_and_is_logged(): void {
    $log = new RecordingLogger();
    $lock = new PostgresAdvisoryProcessLock($this->db, $log);

    $lock->release(new LockHandle(new LockKey('acme', '', 3), 'pg:nope'));

    self::assertSame(0, $lock->held_count());
    self::assertStringContainsString('unknown', $log->text());
  }

  public function test_a_failed_backend_release_is_logged_not_thrown(): void {
    $log = new RecordingLogger();
    $conn = PostgresDatabase::connect();
    $lock = new PostgresAdvisoryProcessLock($conn, $log);
    $h = $lock->acquire(new LockKey('acme', '', 4), 1.0);
    $conn->close(); // the session is gone, and with it the lock

    $lock->release($h);

    self::assertSame(0, $lock->held_count());
    self::assertStringContainsString('release', $log->text());
  }

  public function test_wrapped_in_reentrant_lock_postgres_sees_one_acquisition_per_wake(): void {
    $inner = new PostgresAdvisoryProcessLock($this->db);
    $lock = new ReentrantProcessLock($inner);
    $other = new PostgresAdvisoryProcessLock($this->secondConnection());
    $key = new LockKey('acme', '', 12);

    $outer = $lock->acquire($key, 1.0);
    $nested = $lock->acquire($key, 1.0);
    self::assertSame(1, $inner->held_count());
    self::assertSame(1, $this->advisoryLocksHeldBySession());

    $lock->release($nested);
    self::assertSame(1, $inner->held_count(), 'still held by the outer acquisition');
    $lock->release($outer);
    self::assertSame(0, $inner->held_count());
    self::assertSame(0, $this->advisoryLocksHeldBySession());

    $other->acquire($key, 0.2);
  }

  public function test_constructing_on_a_pooled_dsn_is_silent_because_web_requests_build_but_never_lock(): void {
    $log = new RecordingLogger();
    new PostgresAdvisoryProcessLock($this->pooledConnection(), $log, PoolerPolicy::Refuse);

    self::assertSame('', $log->text());
  }

  public function test_the_first_acquire_on_a_pooled_dsn_warns_by_default(): void {
    $log = new RecordingLogger();
    $lock = new PostgresAdvisoryProcessLock($this->pooledConnection(), $log);

    try {
      $lock->acquire(new LockKey('acme', '', 1), 0.1);
    } catch (LockNotAcquired) {
      // the fake pooler host does not resolve
    }

    self::assertStringContainsString('pooled', $log->text());
  }

  public function test_the_first_acquire_on_a_pooled_dsn_is_refused_when_the_policy_says_so(): void {
    $lock = new PostgresAdvisoryProcessLock($this->pooledConnection(), null, PoolerPolicy::Refuse);

    $this->expectException(PooledConnectionRefused::class);
    $lock->acquire(new LockKey('acme', '', 1), 0.1);
  }

  public function test_a_direct_dsn_is_not_warned_about(): void {
    $log = new RecordingLogger();
    $lock = new PostgresAdvisoryProcessLock($this->db, $log, PoolerPolicy::Refuse);
    $lock->acquire(new LockKey('acme', '', 1), 0.1);

    self::assertSame('', $log->text());
  }

  /** Never connects: DBAL connects lazily, and the pooler check reads params only. */
  private function pooledConnection(): Connection {
    return DriverManager::getConnection([
      'driver' => 'pdo_pgsql', 'host' => 'ep-cool-name-123456-pooler.eu-central-1.aws.neon.tech',
      'port' => 5432, 'user' => 'u', 'password' => 'p', 'dbname' => 'd',
    ]);
  }

  private function advisoryLocksHeldBySession(): int {
    return (int) $this->db->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted");
  }

  /**
   * A child PHP process (another session) takes the lock, prints "locked",
   * holds it for $holdMs and exits, which ends its session and frees the lock.
   *
   * @return array{0: resource, 1: array<int, resource>}
   */
  private function holdInChildProcess(LockKey $key, int $holdMs): array {
    $p = PostgresDatabase::params();
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'], $p['dbname']);
    $code = sprintf(
      '$db = new PDO(%s, %s, %s); $db->query("SELECT pg_advisory_lock(%d)"); echo "locked\n"; fflush(STDOUT); usleep(%d); exit(0);',
      var_export($dsn, true), var_export($p['user'], true), var_export($p['password'] ?? '', true), $key->postgres_key(), $holdMs * 1000
    );
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    self::assertIsResource($proc);
    $line = fgets($pipes[1]);
    if ($line !== "locked\n") {
      // Read stderr only on failure: reading it blocks until the child exits.
      self::fail('the child did not take the lock: ' . stream_get_contents($pipes[2]));
    }
    return [$proc, $pipes];
  }
}
