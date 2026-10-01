<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Symfony\Persistence\ConnectionTopology;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;

/**
 * IProcessLock on Postgres session advisory locks (register 3.7, 5.2).
 *
 * - acquire(): `SELECT pg_try_advisory_lock(key)` polled every 50-200 ms
 *   (jittered) until the deadline. Key: LockKey::postgres_key() =
 *   (crc32(consumer_prefix) << 32) | (process_id & 0xffffffff). Only a
 *   definite `true` enters; `false` until the deadline, or any query error
 *   (an aborted transaction, a dropped connection), throws LockNotAcquired
 *   with the driver error as previous (bug 1). Nothing is held afterwards.
 * - release(): `pg_advisory_unlock(key)`, called by the runner in `finally`.
 *   Never throws: an unknown or stale handle, a `false` unlock (the session
 *   was lost, so was the lock) or a query error is logged as a bug.
 * - release_all(): unlocks every acquisition still outstanding through
 *   this instance (RuntimeReset's leak repair, CR-4) and returns the count.
 *
 * The lock is SESSION scoped: it survives commits and is held across the
 * several independently committed step commands of one wake. It is not
 * re-entrant per instance in the port's sense (Postgres would count a
 * second acquisition on the same session); wrap it in ReentrantProcessLock
 * so Postgres sees one acquisition per wake.
 *
 * Connection: a direct, non-pooled session. A transaction pooler hands the
 * next statement to another backend, so the lock would be taken and
 * released on different sessions or leak. On a connection that looks
 * pooled (ConnectionTopology) the first acquire() logs a warning (Warn, the
 * default) or throws PooledConnectionRefused (Refuse). A reconnect also
 * drops the lock silently; process saves are version-fenced for that.
 */
final class PostgresAdvisoryProcessLock implements IProcessLock {

  /** @var array<string, LockKey> token → key, outstanding acquisitions */
  private array $held = [];

  private int $sequence = 0;

  private readonly LoggerInterface $logger;

  /** @var \Closure(int): void */
  private readonly \Closure $sleeper;

  private readonly PoolerPolicy $pooler;

  private bool $topologyChecked = false;

  /**
   * @param ?\Closure(int): void $sleeper sleeps the given microseconds (tests)
   */
  public function __construct(
    private readonly Connection $connection,
    ?LoggerInterface $logger = null,
    PoolerPolicy $pooler = PoolerPolicy::Warn,
    private readonly int $pollMinMs = 50,
    private readonly int $pollMaxMs = 200,
    ?\Closure $sleeper = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
    $this->sleeper = $sleeper ?? static function (int $micros): void { usleep($micros); };
    $this->pooler = $pooler;
  }

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $this->checkTopologyOnce();
    $key = $k->postgres_key();
    $deadline = microtime(true) + max(0.0, $timeoutSeconds);

    while (true) {
      try {
        $acquired = $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$key], [ParameterType::INTEGER]);
      } catch (\Throwable $e) {
        throw new LockNotAcquired(sprintf('Process lock %s (key %d) errored: %s', $k->id(), $key, $e->getMessage()), 0, $e);
      }

      if ($acquired === true || $acquired === 't' || $acquired === 1 || $acquired === '1') {
        $token = 'pg:' . $key . ':' . (++$this->sequence);
        $this->held[$token] = $k;
        return new LockHandle($k, $token);
      }

      $remaining = $deadline - microtime(true);
      if ($remaining <= 0) {
        throw new LockNotAcquired(sprintf('Process lock %s (key %d) timed out after %.2f s', $k->id(), $key, $timeoutSeconds));
      }
      $pause = random_int($this->pollMinMs, max($this->pollMinMs, $this->pollMaxMs)) * 1000;
      ($this->sleeper)((int) min($pause, $remaining * 1_000_000));
    }
  }

  public function release(LockHandle $h): void {
    $key = $this->held[$h->token] ?? null;
    if ($key === null) {
      $this->logger->error(sprintf('[ddd lock] release of an unknown or already-released handle for %s (bug)', $h->key->id()));
      return;
    }
    unset($this->held[$h->token]);
    $this->unlock($key, 'release');
  }

  public function held_count(): int {
    return count($this->held);
  }

  public function release_all(): int {
    $held = $this->held;
    $this->held = [];
    foreach ($held as $key) {
      $this->unlock($key, 'force release');
    }
    return count($held);
  }

  /**
   * The pooler check runs at the first acquire(), not at construction: web
   * requests build the runner (and so this lock) on a pooled connection
   * legitimately, but never take a process lock on sf (5.2).
   */
  private function checkTopologyOnce(): void {
    if ($this->topologyChecked) {
      return;
    }
    $why = ConnectionTopology::pooler($this->connection->getParams());
    if ($why === null) {
      $this->topologyChecked = true;
      return;
    }
    $message = "[ddd lock] the process-lock connection looks pooled ($why); "
      . 'session advisory locks need a direct (non-pooled) connection (register 5.2)';
    if ($this->pooler === PoolerPolicy::Refuse) {
      throw new PooledConnectionRefused($message);
    }
    $this->topologyChecked = true;
    $this->logger->warning($message);
  }

  private function unlock(LockKey $k, string $why): void {
    try {
      $released = $this->connection->fetchOne('SELECT pg_advisory_unlock(?)', [$k->postgres_key()], [ParameterType::INTEGER]);
      if (!($released === true || $released === 't' || $released === 1 || $released === '1')) {
        $this->logger->error(sprintf(
          '[ddd lock] %s of %s returned false: this session no longer held it (reconnect?) (bug)', $why, $k->id()
        ));
      }
    } catch (\Throwable $e) {
      $this->logger->error(sprintf('[ddd lock] backend %s failed for %s (bug): %s', $why, $k->id(), $e->getMessage()));
    }
  }
}
