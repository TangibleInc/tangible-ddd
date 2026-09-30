<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * Deterministic clock for in-memory runs and tests (register 3.1, "mem").
 * Time moves only through advance() / set(). Cannot be shared with a fresh
 * `php` process; use Testing\EnvOffsetClock for cross-process scenarios.
 */
final class FrozenClock implements IClock {

  private \DateTimeImmutable $now;

  public function __construct(?\DateTimeImmutable $start = null) {
    $this->now = self::utc($start ?? new \DateTimeImmutable('now'));
  }

  public function now(): \DateTimeImmutable {
    return $this->now;
  }

  /**
   * Move time forward (or backward, with a "-" relative string).
   *
   * @param string $interval an ISO 8601 duration ("PT25H") or a relative
   *                         date string DateTimeImmutable::modify accepts ("90 seconds")
   * @throws \InvalidArgumentException when the interval cannot be parsed
   */
  public function advance(string $interval): void {
    if (str_starts_with($interval, 'P')) {
      try {
        $this->now = $this->now->add(new \DateInterval($interval));
        return;
      } catch (\Exception $e) {
        throw new \InvalidArgumentException("Unparseable clock interval '$interval'", 0, $e);
      }
    }

    try {
      $next = @$this->now->modify($interval);
    } catch (\Throwable $e) { // DateMalformedStringException on newer PHP
      throw new \InvalidArgumentException("Unparseable clock interval '$interval'", 0, $e);
    }
    if ($next === false) {
      throw new \InvalidArgumentException("Unparseable clock interval '$interval'");
    }
    $this->now = self::utc($next);
  }

  public function set(\DateTimeImmutable $now): void {
    $this->now = self::utc($now);
  }

  private static function utc(\DateTimeImmutable $t): \DateTimeImmutable {
    return $t->setTimezone(new \DateTimeZone('UTC'));
  }
}
