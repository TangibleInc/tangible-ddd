<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\IClock;

/**
 * TEST-ONLY clock for cross-process scenarios (register 3.1, ruling #84).
 *
 * System time plus the `DDD_CLOCK_OFFSET` environment variable. The offset is
 * an integer number of seconds ("3600", "-60") or an ISO 8601 duration,
 * optionally negated with a leading '-' ("P1DT1H", "-P1D").
 * `examples/plain-php-durable` and the harness use it so a timeout is due in a
 * second, fresh `php` drain process.
 *
 * The variable is read and validated once, in the constructor, so a bad value
 * fails at wiring time and now() keeps the IClock "never throws" rule. Each
 * process (or each test) that wants a different offset builds a new instance.
 *
 * Never wired by default by any host.
 */
final class EnvOffsetClock implements IClock {

  public const ENV = 'DDD_CLOCK_OFFSET';

  private readonly ?\DateInterval $offset;

  /** @throws \InvalidArgumentException when the variable is set but unparseable */
  public function __construct() {
    $this->offset = self::parse(getenv(self::ENV));
  }

  public function now(): \DateTimeImmutable {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return $this->offset === null ? $now : $now->add($this->offset);
  }

  private static function parse(string|false $raw): ?\DateInterval {
    if ($raw === false || trim($raw) === '') {
      return null;
    }
    $raw = trim($raw);

    if (preg_match('/^(-?)(\d+)$/', $raw, $m)) {
      $interval = new \DateInterval('PT' . $m[2] . 'S');
      $interval->invert = $m[1] === '-' ? 1 : 0;
      return $interval;
    }

    if (preg_match('/^(-?)(P.+)$/', $raw, $m)) {
      try {
        $interval = new \DateInterval($m[2]);
      } catch (\Exception $e) {
        throw new \InvalidArgumentException(self::ENV . " '$raw' is not an ISO 8601 duration", 0, $e);
      }
      $interval->invert = $m[1] === '-' ? 1 : 0;
      return $interval;
    }

    throw new \InvalidArgumentException(self::ENV . " '$raw' must be seconds or an ISO 8601 duration");
  }
}
