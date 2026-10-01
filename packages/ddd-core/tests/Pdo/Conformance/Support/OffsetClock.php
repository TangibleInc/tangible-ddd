<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Runtime\IClock;
use TangibleDDD\Testing\EnvOffsetClock;

/**
 * The test process's side of EnvOffsetClock (register 3.1, ruling #84):
 * system time plus a whole number of seconds that advanceClock() grows.
 * A fresh `php` process gets the same offset as DDD_CLOCK_OFFSET and builds
 * an EnvOffsetClock, so both read the same "now" up to real elapsed time,
 * which only moves forward.
 */
final class OffsetClock implements IClock {

  private int $offset = 0;

  public function now(): \DateTimeImmutable {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return $this->offset === 0 ? $now : $now->modify("{$this->offsetString()} seconds");
  }

  public function advance(int $seconds): void {
    $this->offset += $seconds;
  }

  /** The value a fresh process reads as EnvOffsetClock::ENV. */
  public function offsetSeconds(): int {
    return $this->offset;
  }

  public function envName(): string {
    return EnvOffsetClock::ENV;
  }

  private function offsetString(): string {
    return ($this->offset >= 0 ? '+' : '') . $this->offset;
  }
}
