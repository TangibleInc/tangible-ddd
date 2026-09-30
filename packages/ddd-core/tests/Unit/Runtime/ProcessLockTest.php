<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Testing\InMemoryProcessLock;

final class ProcessLockTest extends TestCase {

  public function test_lock_key_names_are_namespaced_by_consumer_and_tenant(): void {
    $a = new LockKey('acme', '', 7);
    $b = new LockKey('other', '', 7);
    $c = new LockKey('acme', '2', 7);

    self::assertSame('ddd:' . sha1('acme||7'), $a->mysqlName());
    self::assertLessThanOrEqual(64, strlen($a->mysqlName()));
    self::assertNotSame($a->mysqlName(), $b->mysqlName());
    self::assertNotSame($a->mysqlName(), $c->mysqlName());
  }

  public function test_lock_key_postgres_key_follows_section_5_2(): void {
    $k = new LockKey('acme', '', 7);
    $expected = (crc32('acme') << 32) | (7 & 0xffffffff);

    self::assertSame($expected, $k->postgresKey());
    self::assertNotSame($k->postgresKey(), (new LockKey('acme', '', 8))->postgresKey());
  }

  public function test_in_memory_lock_acquires_and_releases(): void {
    $lock = new InMemoryProcessLock();
    self::assertInstanceOf(IProcessLock::class, $lock);

    $h = $lock->acquire(new LockKey('acme', '', 1), 1.0);
    self::assertSame(1, $lock->heldCount());

    $lock->release($h);
    self::assertSame(0, $lock->heldCount());
  }

  public function test_in_memory_lock_is_not_reentrant_like_a_raw_adapter(): void {
    $lock = new InMemoryProcessLock();
    $lock->acquire(new LockKey('acme', '', 1), 0.0);

    $this->expectException(LockNotAcquired::class);
    $lock->acquire(new LockKey('acme', '', 1), 0.0);
  }

  public function test_contention_from_another_connection_throws_and_does_not_enter(): void {
    $lock = new InMemoryProcessLock();
    $key = new LockKey('acme', '', 1);
    $lock->holdElsewhere($key);

    try {
      $lock->acquire($key, 0.5);
      self::fail('expected LockNotAcquired');
    } catch (LockNotAcquired $e) {
      self::assertStringContainsString('timeout', $e->getMessage());
    }
    self::assertSame(0, $lock->heldCount());

    $lock->releaseElsewhere($key);
    $lock->acquire($key, 0.5);
    self::assertSame(1, $lock->heldCount());
  }

  public function test_a_backend_error_is_lock_not_acquired_too(): void {
    $lock = new InMemoryProcessLock();
    $lock->failNextAcquire('GET_LOCK returned NULL');

    try {
      $lock->acquire(new LockKey('acme', '', 1), 1.0);
      self::fail('expected LockNotAcquired');
    } catch (LockNotAcquired $e) {
      self::assertStringContainsString('NULL', $e->getMessage());
    }
    self::assertSame(0, $lock->heldCount());

    // one-shot: the next acquire succeeds
    $lock->acquire(new LockKey('acme', '', 1), 1.0);
    self::assertSame(1, $lock->heldCount());
  }

  public function test_release_of_an_unknown_handle_never_throws(): void {
    $lock = new InMemoryProcessLock();
    $h = $lock->acquire(new LockKey('acme', '', 1), 0.0);
    $lock->release($h);
    $lock->release($h); // double release: logged as a bug, no throw

    self::assertSame(0, $lock->heldCount());
    self::assertCount(1, $lock->releaseBugs());
  }

  public function test_reentrant_wrapper_acquires_the_backend_once_per_key(): void {
    $inner = new InMemoryProcessLock();
    $lock = new ReentrantProcessLock($inner);
    $key = new LockKey('acme', '', 1);

    $outer = $lock->acquire($key, 1.0);     // timeout path
    $nested = $lock->acquire($key, 1.0);    // with_process inside it

    self::assertSame(2, $lock->heldCount());
    self::assertSame(1, $inner->heldCount());
    self::assertSame(1, $inner->acquireCount());

    $lock->release($nested);
    self::assertSame(1, $inner->heldCount(), 'inner stays held until the outermost release');

    $lock->release($outer);
    self::assertSame(0, $lock->heldCount());
    self::assertSame(0, $inner->heldCount());
  }

  public function test_reentrant_wrapper_keeps_keys_independent(): void {
    $inner = new InMemoryProcessLock();
    $lock = new ReentrantProcessLock($inner);

    $a = $lock->acquire(new LockKey('acme', '', 1), 1.0);
    $b = $lock->acquire(new LockKey('acme', '', 2), 1.0);
    self::assertSame(2, $inner->heldCount());

    $lock->release($a);
    $lock->release($b);
    self::assertSame(0, $inner->heldCount());
  }

  public function test_reentrant_wrapper_propagates_lock_not_acquired_and_stays_balanced(): void {
    $inner = new InMemoryProcessLock();
    $lock = new ReentrantProcessLock($inner);
    $inner->failNextAcquire('error');

    try {
      $lock->acquire(new LockKey('acme', '', 1), 1.0);
      self::fail('expected LockNotAcquired');
    } catch (LockNotAcquired) {
    }
    self::assertSame(0, $lock->heldCount());
  }

  public function test_reentrant_wrapper_release_never_throws_even_if_the_backend_does(): void {
    $inner = new class implements IProcessLock {
      public function acquire(LockKey $k, float $timeoutSeconds): \TangibleDDD\Runtime\Lock\LockHandle {
        return new \TangibleDDD\Runtime\Lock\LockHandle($k, 'x');
      }
      public function release(\TangibleDDD\Runtime\Lock\LockHandle $h): void {
        throw new \RuntimeException('connection gone');
      }
      public function heldCount(): int { return 0; }
    };
    $logged = [];
    $lock = new ReentrantProcessLock($inner, static function (string $msg) use (&$logged) { $logged[] = $msg; });

    $h = $lock->acquire(new LockKey('acme', '', 1), 1.0);
    $lock->release($h);

    self::assertSame(0, $lock->heldCount());
    self::assertCount(1, $logged);
    self::assertStringContainsString('connection gone', $logged[0]);
  }

  public function test_reentrant_wrapper_ignores_a_foreign_or_double_release(): void {
    $lock = new ReentrantProcessLock(new InMemoryProcessLock(), static function () {});
    $h = $lock->acquire(new LockKey('acme', '', 1), 1.0);
    $lock->release($h);
    $lock->release($h);

    self::assertSame(0, $lock->heldCount());
  }
}
