<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\ScriptedConnection;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\MySqlNamedLock;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;

abstract class MySqlNamedLockCases extends PdoTestCase {

  private function holder(IHostConnection $db, string $name): ?int {
    $row = $db->fetchOne('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS me', [$name]);
    return $row['owner'] === null ? null : (int) $row['owner'];
  }

  private function connectionId(IHostConnection $db): int {
    return (int) $db->fetchOne('SELECT CONNECTION_ID() AS id')['id'];
  }

  public function test_the_lock_name_is_ddd_plus_sha1_of_consumer_tenant_and_process_id(): void {
    $key = new LockKey('acme', '3', 42);
    self::assertSame('ddd:' . sha1('acme|3|42'), MySqlNamedLock::nameOf($key));
    self::assertLessThanOrEqual(64, strlen(MySqlNamedLock::nameOf($key)));
  }

  public function test_acquire_takes_the_named_lock_on_this_session_and_release_frees_it(): void {
    $lock = new MySqlNamedLock($this->db);
    self::assertInstanceOf(IProcessLock::class, $lock);
    $key = new LockKey('acme', '', 42);
    $name = MySqlNamedLock::nameOf($key);

    $handle = $lock->acquire($key, 1.0);
    self::assertSame($key, $handle->key);
    self::assertSame($this->connectionId($this->db), $this->holder($this->db, $name));
    self::assertSame(1, $lock->heldCount());

    $lock->release($handle);
    self::assertNull($this->holder($this->db, $name));
    self::assertSame(0, $lock->heldCount());
  }

  public function test_contention_waits_for_the_timeout_then_fails_closed(): void {
    $other = $this->otherConnection();
    $key = new LockKey('acme', '', 7);
    $otherLock = new MySqlNamedLock($other);
    $theirs = $otherLock->acquire($key, 1.0);
    $lock = new MySqlNamedLock($this->db);

    $t0 = microtime(true);
    try {
      $lock->acquire($key, 1.0);
      self::fail('expected LockNotAcquired');
    } catch (LockNotAcquired $e) {
      self::assertStringContainsString('timed out', $e->getMessage());
    }
    self::assertGreaterThanOrEqual(0.9, microtime(true) - $t0, 'waited up to the timeout');
    self::assertSame(0, $lock->heldCount());

    $otherLock->release($theirs);
    $mine = $lock->acquire($key, 1.0);
    self::assertSame(1, $lock->heldCount(), 'later succeeds');
    $lock->release($mine);
  }

  public function test_a_null_result_or_a_query_error_never_counts_as_acquired(): void {
    $logger = new RecordingLogger();
    foreach ([[['acquired' => null]], [['acquired' => 0]], [['acquired' => '0']], [null], [new \PDOException('server has gone away')]] as $script) {
      $lock = new MySqlNamedLock(new ScriptedConnection($script), $logger);
      try {
        $lock->acquire(new LockKey('acme', '', 1), 0.0);
        self::fail('expected LockNotAcquired for ' . json_encode($script, JSON_PARTIAL_OUTPUT_ON_ERROR));
      } catch (LockNotAcquired) {
        self::assertSame(0, $lock->heldCount());
      }
    }

    $lock = new MySqlNamedLock(new ScriptedConnection([new \PDOException('gone')]));
    try {
      $lock->acquire(new LockKey('acme', '', 1), 0.0);
    } catch (LockNotAcquired $e) {
      self::assertInstanceOf(\PDOException::class, $e->getPrevious());
    }
  }

  public function test_only_a_definite_one_enters(): void {
    $lock = new MySqlNamedLock(new ScriptedConnection([['acquired' => 1], ['released' => 1]]));
    $h = $lock->acquire(new LockKey('acme', '', 1), 0.0);
    self::assertSame(1, $lock->heldCount());
    $lock->release($h);
    self::assertSame(0, $lock->heldCount());
  }

  public function test_two_consumers_with_the_same_process_id_do_not_block_each_other(): void {
    $a = new MySqlNamedLock($this->db);
    $b = new MySqlNamedLock($this->otherConnection());

    $ha = $a->acquire(new LockKey('acme', '', 42), 0.0);
    $hb = $b->acquire(new LockKey('other', '', 42), 0.0);
    $hc = $b->acquire(new LockKey('acme', '2', 42), 0.0);

    $a->release($ha);
    $b->release($hb);
    $b->release($hc);
    self::assertSame(0, $a->heldCount() + $b->heldCount());
  }

  public function test_release_never_throws_and_logs_a_failed_or_unknown_release(): void {
    $logger = new RecordingLogger();
    $lock = new MySqlNamedLock(new ScriptedConnection([['acquired' => 1], new \PDOException('gone')]), $logger);
    $h = $lock->acquire(new LockKey('acme', '', 1), 0.0);

    $lock->release($h);
    $lock->release($h);

    self::assertSame(0, $lock->heldCount());
    self::assertCount(2, $logger->records);
    self::assertStringContainsString('gone', $logger->messages()[0]);
    self::assertStringContainsString('unknown', $logger->messages()[1]);
  }

  public function test_force_release_all_drops_every_lock_this_instance_holds(): void {
    $lock = new MySqlNamedLock($this->db);
    $k1 = new LockKey('acme', '', 1);
    $k2 = new LockKey('acme', '', 2);
    $h1 = $lock->acquire($k1, 0.0);
    $lock->acquire($k2, 0.0);
    $hostOwn = $this->db->fetchOne("SELECT GET_LOCK('host-own-lock', 0) AS r")['r'];
    self::assertSame(1, (int) $hostOwn);

    self::assertSame(2, $lock->forceReleaseAll());

    self::assertSame(0, $lock->heldCount());
    self::assertNull($this->holder($this->db, MySqlNamedLock::nameOf($k1)));
    self::assertNull($this->holder($this->db, MySqlNamedLock::nameOf($k2)));
    self::assertNotNull($this->holder($this->db, 'host-own-lock'), 'locks this instance did not take are untouched');
    $lock->release($h1); // stale handle: ignored
    self::assertSame(0, $lock->forceReleaseAll());
    $this->db->fetchOne("SELECT RELEASE_LOCK('host-own-lock') AS r");
  }

  public function test_the_lock_outlives_transactions_on_the_same_connection(): void {
    $lock = new MySqlNamedLock($this->db);
    $key = new LockKey('acme', '', 9);
    $h = $lock->acquire($key, 0.0);

    $this->db->begin();
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['x']);
    $this->db->rollBack();

    self::assertSame($this->connectionId($this->db), $this->holder($this->db, MySqlNamedLock::nameOf($key)));
    $lock->release($h);
  }

  public function test_wrapped_in_the_reentrant_lock_the_backend_sees_one_acquisition(): void {
    $backend = new MySqlNamedLock($this->db);
    $lock = new ReentrantProcessLock($backend);
    $key = new LockKey('acme', '', 5);

    $outer = $lock->acquire($key, 0.0);
    $inner = $lock->acquire($key, 0.0);
    self::assertSame(1, $backend->heldCount());
    $lock->release($inner);
    self::assertNotNull($this->holder($this->db, MySqlNamedLock::nameOf($key)));
    $lock->release($outer);

    self::assertSame(0, $lock->heldCount());
    self::assertSame(0, $backend->heldCount());
    self::assertNull($this->holder($this->db, MySqlNamedLock::nameOf($key)));
  }
}
