<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;

/**
 * In-memory IProcessLock that behaves like a raw, NON-re-entrant adapter
 * (wrap it in ReentrantProcessLock as production does).
 *
 * Test controls: hold_elsewhere() simulates "connection 2 holds the lock"
 * (acquire times out immediately; no real waiting), fail_next_acquire()
 * simulates a NULL / false / error backend result (one-shot).
 */
final class InMemoryProcessLock implements IProcessLock {

  /** @var array<string, string> key id → token held by THIS connection */
  private array $mine = [];

  /** @var array<string, true> key ids held by another connection */
  private array $elsewhere = [];

  private ?string $nextFailure = null;

  private int $acquires = 0;

  private int $seq = 0;

  /** @var list<string> */
  private array $releaseBugs = [];

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $id = $k->id();

    if ($this->nextFailure !== null) {
      $reason = $this->nextFailure;
      $this->nextFailure = null;
      throw new LockNotAcquired("Lock {$k->mysql_name()} not acquired: backend error ($reason)");
    }
    if (isset($this->elsewhere[$id]) || isset($this->mine[$id])) {
      throw new LockNotAcquired(sprintf('Lock %s not acquired: timeout after %.2fs (held)', $k->mysql_name(), $timeoutSeconds));
    }

    $token = 'mem:' . (++$this->seq);
    $this->mine[$id] = $token;
    $this->acquires++;

    return new LockHandle($k, $token);
  }

  public function release(LockHandle $h): void {
    $id = $h->key->id();
    if (($this->mine[$id] ?? null) !== $h->token) {
      $this->releaseBugs[] = "release of a lock not held: {$id}";
      return;
    }
    unset($this->mine[$id]);
  }

  public function held_count(): int {
    return count($this->mine);
  }

  public function release_all(): int {
    $n = count($this->mine);
    $this->mine = [];
    return $n;
  }

  public function hold_elsewhere(LockKey $k): void {
    $this->elsewhere[$k->id()] = true;
  }

  public function release_elsewhere(LockKey $k): void {
    unset($this->elsewhere[$k->id()]);
  }

  public function fail_next_acquire(string $reason): void {
    $this->nextFailure = $reason;
  }

  /** Successful backend acquisitions so far. */
  public function acquisitions(): int {
    return $this->acquires;
  }

  /** @return list<string> releases that would have been logged as bugs */
  public function release_bugs(): array {
    return $this->releaseBugs;
  }
}
