<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Outbox\IRelayPauseStore;

/** In-memory IRelayPauseStore: one row per (holder, selector), glob selectors, expiry honoured. */
final class InMemoryRelayPauseStore implements IRelayPauseStore {

  /** @var array<string, array<string, ?\DateTimeImmutable>> holder → selector → until */
  private array $holds = [];

  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void {
    $this->holds[$holder][$selector] = $until;
  }

  public function release(string $holder, ?string $selector = null): void {
    if ($selector === null) {
      unset($this->holds[$holder]);
      return;
    }
    unset($this->holds[$holder][$selector]);
    if (($this->holds[$holder] ?? null) === []) {
      unset($this->holds[$holder]);
    }
  }

  public function is_paused(string $eventType, \DateTimeImmutable $now): bool {
    foreach ($this->holds as $selectors) {
      foreach ($selectors as $selector => $until) {
        if ($until !== null && $until <= $now) {
          continue;
        }
        if ($selector === $eventType || fnmatch($selector, $eventType)) {
          return true;
        }
      }
    }
    return false;
  }
}
