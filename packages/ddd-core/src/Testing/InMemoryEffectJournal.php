<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * In-memory IEffectJournal (D1) with entry states (ITracksEffectState, E2);
 * keeps an invalidation log for assertions. Enlist it in an
 * InMemoryTransactionBoundary so an invalidate() inside a repair command,
 * and the mark_recorded() inside record()'s transaction, roll back with it.
 * EffectMiddleware stores outside any transaction, so a stored entry
 * survives a rolled-back record() (and stays Performed).
 */
final class InMemoryEffectJournal implements ITracksEffectState, InMemoryTransactional {

  /** @var array<string, EffectEntry> */
  private array $entries = [];

  /** @var list<array{key: string, reason: string}> */
  public array $invalidations = [];

  private readonly IClock $clock;

  public function __construct(?IClock $clock = null) {
    $this->clock = $clock ?? new SystemClock();
  }

  public function find(string $key): ?EffectResult {
    return ($this->entries[$key] ?? null)?->result;
  }

  public function store(string $key, EffectResult $r): void {
    $this->entries[$key] = new EffectEntry($key, $r, EffectState::Performed, $this->clock->now());
  }

  public function invalidate(string $key, string $reason): void {
    if (isset($this->entries[$key])) {
      unset($this->entries[$key]);
    }
    $this->invalidations[] = ['key' => $key, 'reason' => $reason];
  }

  public function mark_recorded(string $key): void {
    $entry = $this->entries[$key] ?? null;
    if ($entry !== null) {
      $this->entries[$key] = new EffectEntry($key, $entry->result, EffectState::Recorded, $entry->performed_at, $this->clock->now());
    }
  }

  public function find_entry(string $key): ?EffectEntry {
    return $this->entries[$key] ?? null;
  }

  public function find_unrecorded(\DateTimeImmutable $performed_before, int $limit): array {
    $due = array_values(array_filter(
      $this->entries,
      static fn (EffectEntry $e) => !$e->is_recorded() && $e->performed_at < $performed_before,
    ));
    usort($due, static fn (EffectEntry $a, EffectEntry $b) => $a->performed_at <=> $b->performed_at);
    return array_slice($due, 0, max(0, $limit));
  }

  public function snapshot(): mixed {
    return [$this->entries, $this->invalidations];
  }

  public function restore(mixed $state): void {
    [$this->entries, $this->invalidations] = $state;
  }
}
