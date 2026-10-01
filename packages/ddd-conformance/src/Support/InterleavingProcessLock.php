<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;

/**
 * Pass-through raw IProcessLock with one interleaving point: a one-shot
 * hook that runs right before the next backend acquire
 * (ProcessHost::beforeNextProcessLockAcquire()). Put it UNDER the core
 * ReentrantProcessLock, so the hook fires only where the backend is asked,
 * once per wake, never on a re-entrant acquisition.
 */
final class InterleavingProcessLock implements IProcessLock {

  /** @var list<callable(LockKey): void> */
  private array $before = [];

  public function __construct(private readonly IProcessLock $inner) {}

  public function inner(): IProcessLock {
    return $this->inner;
  }

  /** @param callable(LockKey): void $fn */
  public function beforeNextAcquire(callable $fn): void {
    $this->before[] = $fn;
  }

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    if ($this->before !== []) {
      $fn = array_shift($this->before);
      $fn($k);
    }
    return $this->inner->acquire($k, $timeoutSeconds);
  }

  public function release(LockHandle $h): void {
    $this->inner->release($h);
  }

  public function heldCount(): int {
    return $this->inner->heldCount();
  }

  public function forceReleaseAll(): int {
    return $this->inner->forceReleaseAll();
  }
}
