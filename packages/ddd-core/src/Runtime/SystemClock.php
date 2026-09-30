<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/** Production clock: wall time in UTC. */
final class SystemClock implements IClock {

  public function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
  }
}
