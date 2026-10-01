<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

/** What the wave-4 fixture processes did, in order (tests reset it). */
final class Trail {

  /** @var list<string> */
  public static array $notes = [];

  public static function note(string $what): void {
    self::$notes[] = $what;
  }

  public static function reset(): void {
    self::$notes = [];
  }
}
