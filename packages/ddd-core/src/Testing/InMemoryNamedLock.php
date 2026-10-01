<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Lock\INamedLock;
use TangibleDDD\Runtime\Lock\LockNotAcquired;

/**
 * In-memory INamedLock, non-re-entrant like a raw adapter. Controls:
 * holdElsewhere() ("connection 2 holds it": acquire times out at once),
 * failNextAcquire() (a NULL / error backend result, one-shot).
 */
final class InMemoryNamedLock implements INamedLock {

  /** @var array<string, true> */
  private array $mine = [];

  /** @var array<string, true> */
  private array $elsewhere = [];

  private ?string $nextFailure = null;

  /** @var list<string> every name acquired, in order */
  public array $acquired = [];

  public function acquire(string $name, float $timeoutSeconds): void {
    if ($this->nextFailure !== null) {
      $reason = $this->nextFailure;
      $this->nextFailure = null;
      throw new LockNotAcquired("Lock $name not acquired: backend error ($reason)");
    }
    if (isset($this->mine[$name]) || isset($this->elsewhere[$name])) {
      throw new LockNotAcquired(sprintf('Lock %s not acquired: timeout after %.2fs (held)', $name, $timeoutSeconds));
    }
    $this->mine[$name] = true;
    $this->acquired[] = $name;
  }

  public function release(string $name): void {
    unset($this->mine[$name]);
  }

  public function heldCount(): int {
    return count($this->mine);
  }

  public function holdElsewhere(string $name): void {
    $this->elsewhere[$name] = true;
  }

  public function failNextAcquire(string $reason): void {
    $this->nextFailure = $reason;
  }
}
