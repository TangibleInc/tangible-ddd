<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

/**
 * Column probe for code that must work on the 0.6 schema and on v8 for an
 * arbitrary prefix (the repair commands carry only a prefix, so the
 * installed-version option is not always at hand). Cached per request;
 * only a positive answer is cached, so a migration later in the request is
 * seen.
 *
 * @internal
 */
final class WpSchemaProbe {

  /** @var array<string, true> */
  private static array $present = [];

  public static function hasColumn(string $table, string $column): bool {
    $key = "$table.$column";
    if (isset(self::$present[$key])) {
      return true;
    }
    $db = $GLOBALS['wpdb'];
    $found = (int) $db->get_var($db->prepare(
      'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
      $table,
      $column
    )) > 0;
    if ($found) {
      self::$present[$key] = true;
    }
    return $found;
  }

  /** @internal test seam */
  public static function reset(): void {
    self::$present = [];
  }
}
