<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

/**
 * UTC timestamps to and from MySQL DATETIME(6). DATETIME carries no zone and
 * is unaffected by the session time_zone, so every durable time is converted
 * to UTC before it is written and parsed as UTC when read (bug 3 fix note).
 * The adapters never use NOW() in SQL: "now" always comes from IClock.
 *
 * @internal
 */
final class Utc {

  private const FORMAT = 'Y-m-d H:i:s.u';

  public static function to_db(\DateTimeInterface $t): string {
    return \DateTimeImmutable::createFromInterface($t)->setTimezone(self::zone())->format(self::FORMAT);
  }

  public static function from_db(string $value): \DateTimeImmutable {
    return new \DateTimeImmutable($value, self::zone());
  }

  public static function from_db_or_null(mixed $value): ?\DateTimeImmutable {
    return $value === null ? null : self::from_db((string) $value);
  }

  private static function zone(): \DateTimeZone {
    static $utc = null;
    return $utc ??= new \DateTimeZone('UTC');
  }
}
