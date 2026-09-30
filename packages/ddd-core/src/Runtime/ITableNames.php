<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * Logical table name → physical table name (register 3.1).
 *
 * pdo / sf: the prefix is passed to the constructor. wp: the WordPress table
 * prefix is read per call (multisite switches it at runtime).
 *
 * Error behaviour: throws \InvalidArgumentException for a logical name that
 * is not [a-z0-9_]+ (names are interpolated into SQL, never bound).
 * Lifetime: stateless.
 */
interface ITableNames {
  public function table(string $logical): string;
}
