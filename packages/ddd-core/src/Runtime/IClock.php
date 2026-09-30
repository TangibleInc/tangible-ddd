<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * The one source of "now" for durable times (register 3.1).
 *
 * Error behaviour: never throws.
 * Contract: always returns UTC. Every durable time (`due_at`, `scheduled_at`,
 * `lease_until`, `next_attempt_at`) is computed from this clock and stored and
 * parsed as UTC explicitly (bug 3 fix note).
 * Lifetime: stateless for callers; implementations may be shared process-wide.
 */
interface IClock {
  public function now(): \DateTimeImmutable;
}
