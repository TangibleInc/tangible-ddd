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
 * Test controls: holdElsewhere() simulates "connection 2 holds the lock"
 * (acquire times out immediately; no real waiting), failNextAcquire()
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
      throw new LockNotAcquired("Lock {$k->mysqlName()} not acquired: backend error ($reason)");
    }
    if (isset($this->elsewhere[$id]) || isset($this->mine[$id])) {
      throw new LockNotAcquired(sprintf('Lock %s not acquired: timeout after %.2fs (held)', $k->mysqlName(), $timeoutSeconds));
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

  public function heldCount(): int {
    return count($this->mine);
  }

  public function forceReleaseAll(): int {
    $n = count($this->mine);
    $this->mine = [];
    return $n;
  }

  public function holdElsewhere(LockKey $k): void {
    $this->elsewhere[$k->id()] = true;
  }

  public function releaseElsewhere(LockKey $k): void {
    unset($this->elsewhere[$k->id()]);
  }

  public function failNextAcquire(string $reason): void {
    $this->nextFailure = $reason;
  }

  /** Successful backend acquisitions so far. */
  public function acquireCount(): int {
    return $this->acquires;
  }

  /** @return list<string> releases that would have been logged as bugs */
  public function releaseBugs(): array {
    return $this->releaseBugs;
  }
}
