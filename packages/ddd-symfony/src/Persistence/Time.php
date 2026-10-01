<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

/**
 * UTC timestamps to and from `timestamptz` columns. Every durable time is
 * written with an explicit +00:00 offset and read back as UTC, whatever the
 * session TimeZone is.
 *
 * @internal
 */
final class Time {

  public static function toDb(\DateTimeImmutable $t): string {
    return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
  }

  public static function fromDb(string $value): \DateTimeImmutable {
    return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
  }

  public static function fromDbOrNull(?string $value): ?\DateTimeImmutable {
    return $value === null ? null : self::fromDb($value);
  }
}
