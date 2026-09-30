<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\IClock;

/**
 * TEST-ONLY clock for cross-process scenarios (register 3.1, ruling #84).
 *
 * System time plus the `DDD_CLOCK_OFFSET` environment variable, read on every
 * call. The offset is an integer number of seconds (may be negative) or an
 * ISO 8601 duration ("P1DT1H"). `examples/plain-php-durable` and the harness
 * use it so a timeout is due in a second, fresh `php` drain process.
 *
 * Never wired by default by any host.
 *
 * @throws \InvalidArgumentException from now() when the variable is set but unparseable
 */
final class EnvOffsetClock implements IClock {

  public const ENV = 'DDD_CLOCK_OFFSET';

  public function now(): \DateTimeImmutable {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $raw = getenv(self::ENV);

    if ($raw === false || trim($raw) === '') {
      return $now;
    }
    $raw = trim($raw);

    if (preg_match('/^-?\d+$/', $raw)) {
      return $now->modify(sprintf('%+d seconds', (int) $raw));
    }

    if (preg_match('/^P(?=.)/', $raw)) {
      try {
        return $now->add(new \DateInterval($raw));
      } catch (\Exception $e) {
        throw new \InvalidArgumentException(self::ENV . " '$raw' is not an ISO 8601 duration", 0, $e);
      }
    }

    throw new \InvalidArgumentException(self::ENV . " '$raw' must be seconds or an ISO 8601 duration");
  }
}
