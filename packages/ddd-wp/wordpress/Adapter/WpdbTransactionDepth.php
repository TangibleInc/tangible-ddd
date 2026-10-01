<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

/**
 * How many wpdb transactions THIS request has open through DDD boundaries.
 *
 * wpdb has no inTransaction(), and every DDD boundary on WordPress wraps the
 * one global connection, so the checked WpdbTransactionBoundary and the 0.6
 * UncheckedWpdbTransactionBoundary (TransactionMiddleware) share this count:
 * a process save made while a legacy command transaction is open sees it as
 * open and joins it instead of issuing a nested START TRANSACTION, which
 * MySQL would turn into an implicit COMMIT of the outer one.
 *
 * Transactions opened by raw `$wpdb->query('START TRANSACTION')` elsewhere
 * are invisible here. Process-static; balanced by the boundaries' finally.
 *
 * @internal
 */
final class WpdbTransactionDepth {

  private static int $depth = 0;

  public static function enter(): int {
    return ++self::$depth;
  }

  public static function leave(): void {
    self::$depth = max(0, self::$depth - 1);
  }

  public static function current(): int {
    return self::$depth;
  }

  /** @internal test seam */
  public static function resetForTests(): void {
    self::$depth = 0;
  }
}
