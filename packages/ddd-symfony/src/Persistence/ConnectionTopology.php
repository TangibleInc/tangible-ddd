<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Is this DBAL connection likely a transaction pooler (PgBouncer in
 * transaction mode, a Neon `-pooler` endpoint)? Session state does not
 * survive such a pooler: advisory session locks, LISTEN and SET are lost or
 * leak to another client (register 2, 5.2). Workers and `ddd:relay` need a
 * direct connection; web requests may be pooled, because on sf they never
 * take process locks.
 *
 * Detection reads the connection parameters only and never connects:
 * a host containing `-pooler` (Neon) or port 6432 (the PgBouncer default),
 * on the primary params or any replica. It is a heuristic; a pooler on
 * another port is not detected.
 */
final class ConnectionTopology {

  public static function isPooled(Connection $connection): bool {
    return self::describePooler($connection->getParams()) !== null;
  }

  /**
   * @param array<string, mixed> $params DBAL connection params
   * @return ?string why the params look pooled, null when they look direct
   */
  public static function describePooler(array $params): ?string {
    $candidates = [$params];
    if (isset($params['primary']) && is_array($params['primary'])) {
      $candidates[] = $params['primary'];
    }
    foreach ((array) ($params['replica'] ?? []) as $replica) {
      if (is_array($replica)) {
        $candidates[] = $replica;
      }
    }

    foreach ($candidates as $p) {
      $host = (string) ($p['host'] ?? '');
      if ($host !== '' && str_contains(strtolower($host), '-pooler')) {
        return "host $host is a pooler endpoint (-pooler)";
      }
      if ((int) ($p['port'] ?? 0) === 6432) {
        return 'port 6432 is the PgBouncer default';
      }
    }
    return null;
  }
}
