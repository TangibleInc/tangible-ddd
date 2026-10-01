<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;

/**
 * In-memory IEffectJournal (D1); keeps an invalidation log for assertions.
 * Enlist it in an InMemoryTransactionBoundary so an invalidate() inside a
 * repair command rolls back with it. EffectMiddleware stores outside any
 * transaction, so a stored entry survives a rolled-back record().
 */
final class InMemoryEffectJournal implements IEffectJournal, InMemoryTransactional {

  /** @var array<string, EffectResult> */
  private array $entries = [];

  /** @var list<array{key: string, reason: string}> */
  public array $invalidations = [];

  public function find(string $key): ?EffectResult {
    return $this->entries[$key] ?? null;
  }

  public function store(string $key, EffectResult $r): void {
    $this->entries[$key] = $r;
  }

  public function invalidate(string $key, string $reason): void {
    if (isset($this->entries[$key])) {
      unset($this->entries[$key]);
    }
    $this->invalidations[] = ['key' => $key, 'reason' => $reason];
  }

  public function snapshotState(): mixed {
    return [$this->entries, $this->invalidations];
  }

  public function restoreState(mixed $state): void {
    [$this->entries, $this->invalidations] = $state;
  }
}
