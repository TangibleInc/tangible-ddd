<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IConsumerIdentity;

/**
 * The schema gate of the v8 adapters: the installed framework schema
 * version of a consumer prefix, read from the same per-prefix option
 * `{prefix}_ddd_schema_version` the migrator writes (migrations.php,
 * ddd_schema_version_key()).
 *
 * The v8 code paths (claim_token claims and fencing, the intent table, the
 * delivery ledger, the version-fenced process store) are wired only for a
 * consumer whose v8 migration has run; until then (the request between a
 * deploy and its first init:3 / admin_init migration, or a consumer that
 * never migrates) the 0.6-schema paths stay in use.
 *
 * Read per call (get_option is cached by WordPress for the request), so a
 * migration earlier in the same request is seen.
 */
final class WpSchema {

  public const V8 = 8;

  /** Wave 5 (AW2): `{prefix}_ddd_wakeups.fact`, the fact a parked resume carries. */
  public const V9 = 9;

  /** @var array<string, bool> table => reachable, for the request (outbox_enabled(), processes_enabled()) */
  private static array $reachable = [];

  /**
   * After a failed explicit migration, ddd_maybe_migrate() retries at most
   * this often (option `{prefix}_ddd_migration_retry_at`; delete it to
   * retry on the next request).
   */
  public const MIGRATION_RETRY_SECONDS = 600;

  public static function installed(IConsumerIdentity|string $consumer): int {
    $prefix = is_string($consumer) ? $consumer : $consumer->prefix();
    if (!function_exists('get_option')) {
      return 0;
    }
    return (int) get_option($prefix . '_ddd_schema_version', 0);
  }

  public static function at_least(IConsumerIdentity|string $consumer, int $version): bool {
    return self::installed($consumer) >= $version;
  }

  public static function is_v8(IConsumerIdentity|string $consumer): bool {
    return self::at_least($consumer, self::V8);
  }

  public static function is_v9(IConsumerIdentity|string $consumer): bool {
    return self::at_least($consumer, self::V9);
  }

  /**
   * Whether $table answers a query, probed once per request and cached:
   * the feature gates outbox_enabled() and processes_enabled() read this.
   * ddd_maybe_migrate() forgets the cache after it installed the tables, so
   * a gate probed closed earlier in the same request (a fresh database)
   * opens.
   *
   * @param callable(string): bool $probe
   */
  public static function reachable(string $table, callable $probe): bool {
    return self::$reachable[$table] ??= (bool) $probe($table);
  }

  /** Forget every probed table: the next gate check probes again. */
  public static function forget_tables(): void {
    self::$reachable = [];
  }

  /**
   * Whether the last query on $db failed with a duplicate-key error: MySQL
   * 1062 only, never the SQLSTATE class 23000, which also covers FK and
   * NOT NULL violations (register 3.8).
   */
  public static function is_duplicate_key(\wpdb $db): bool {
    $dbh = $db->dbh ?? null; // protected, read through wpdb::__get
    if ($dbh instanceof \mysqli) {
      return mysqli_errno($dbh) === 1062;
    }
    return str_starts_with((string) $db->last_error, 'Duplicate entry');
  }
}
