<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

use TangibleDDD\Runtime\Support\Log;

/**
 * Core re-entrancy wrapper (register 3.7): counts acquisitions per key so the
 * backend sees one acquisition per wake even when the timeout path acquires
 * and `with_process` acquires again (`lock.reentrant-balance`).
 *
 * heldCount() is the number of outstanding acquisitions through this wrapper.
 * release() never throws: an unknown/double release or a backend release
 * failure is reported through $log and otherwise ignored.
 */
final class ReentrantProcessLock implements IProcessLock {

  /** @var array<string, array{handle: LockHandle, count: int}> */
  private array $held = [];

  private int $ticket = 0;

  /** @var array<string, string> reentrant ticket → key id */
  private array $tickets = [];

  /** @param (\Closure(string):void)|null $log */
  public function __construct(
    private readonly IProcessLock $inner,
    private readonly ?\Closure $log = null,
  ) {}

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    $id = $k->id();

    if (!isset($this->held[$id])) {
      // Throws LockNotAcquired: nothing is recorded, balance unchanged.
      $this->held[$id] = ['handle' => $this->inner->acquire($k, $timeoutSeconds), 'count' => 0];
    }
    $this->held[$id]['count']++;

    $ticket = 'reentrant:' . (++$this->ticket);
    $this->tickets[$ticket] = $id;

    return new LockHandle($k, $ticket);
  }

  public function release(LockHandle $h): void {
    $id = $this->tickets[$h->token] ?? null;
    if ($id === null || !isset($this->held[$id])) {
      Log::write($this->log, sprintf('[ddd lock] release of an unknown or already-released handle for %s (bug)', $h->key->id()));
      return;
    }
    unset($this->tickets[$h->token]);

    if (--$this->held[$id]['count'] > 0) {
      return;
    }

    $inner = $this->held[$id]['handle'];
    unset($this->held[$id]);

    try {
      $this->inner->release($inner);
    } catch (\Throwable $e) {
      Log::write($this->log, sprintf('[ddd lock] backend release failed for %s (bug): %s', $id, $e->getMessage()));
    }
  }

  public function heldCount(): int {
    $n = 0;
    foreach ($this->held as $entry) {
      $n += $entry['count'];
    }
    return $n;
  }
}
