<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;

/**
 * Pass-through raw IProcessLock (the shipped GetLockProcessLock underneath)
 * that counts successful BACKEND acquisitions into a counter shared by
 * every worker of the fixture (ProcessHost::lock_acquisitions()).
 * Sits under ReentrantProcessLock, so re-entrant acquisitions never reach it.
 *
 * With $db set, every backend call runs on that connection (worker 2's own
 * GET_LOCK session), wherever the runner calling it was invoked from.
 */
final class CountingProcessLock implements IProcessLock {

  public function __construct(
    private readonly IProcessLock $inner,
    private readonly \ArrayObject $counter,
    private readonly ?\wpdb $db = null,
  ) {}

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $handle = $this->on(fn () => $this->inner->acquire($k, $timeoutSeconds));
    $this->counter['n'] = ($this->counter['n'] ?? 0) + 1;
    return $handle;
  }

  public function release(LockHandle $h): void {
    $this->on(fn () => $this->inner->release($h));
  }

  public function held_count(): int {
    return $this->inner->held_count();
  }

  public function release_all(): int {
    return $this->on(fn () => $this->inner->release_all());
  }

  private function on(callable $fn): mixed {
    return $this->db === null ? $fn() : ConnectionSwitch::on($this->db, $fn);
  }
}
