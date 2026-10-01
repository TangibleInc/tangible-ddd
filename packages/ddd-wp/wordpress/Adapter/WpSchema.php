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

  public static function installed(IConsumerIdentity|string $consumer): int {
    $prefix = is_string($consumer) ? $consumer : $consumer->prefix();
    if (!function_exists('get_option')) {
      return 0;
    }
    return (int) get_option($prefix . '_ddd_schema_version', 0);
  }

  public static function atLeast(IConsumerIdentity|string $consumer, int $version): bool {
    return self::installed($consumer) >= $version;
  }

  public static function isV8(IConsumerIdentity|string $consumer): bool {
    return self::atLeast($consumer, self::V8);
  }
}
