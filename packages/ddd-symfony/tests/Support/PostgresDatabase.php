<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use TangibleDDD\Symfony\Persistence\PostgresSchema;

/**
 * The test database (DDD_SF_PG_URL, default ddd_w2_symfony_adapters on the
 * local Postgres 16). Tests own their tables: each drops and re-applies the
 * ddd schema in setUp(); nothing is wrapped in a per-test transaction.
 */
final class PostgresDatabase {

  public static function url(): string {
    return (string) (getenv('DDD_SF_PG_URL') ?: 'pgsql://postgres:ddd@127.0.0.1:55432/ddd_w2_symfony_adapters');
  }

  /** @return array<string, mixed> DBAL connection params */
  public static function params(): array {
    return (new DsnParser(['pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse(self::url());
  }

  public static function connect(): Connection {
    return DriverManager::getConnection(self::params());
  }

  public static function ensureDatabaseExists(): void {
    $params = self::params();
    $name = (string) $params['dbname'];
    if (!preg_match('/^[a-z0-9_]+$/', $name)) {
      throw new \RuntimeException("Test database name '$name' must match [a-z0-9_]+");
    }
    $admin = DriverManager::getConnection(['dbname' => 'postgres'] + $params);
    try {
      $exists = $admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$name]);
      if ($exists === false) {
        $admin->executeStatement('CREATE DATABASE ' . $name);
      }
    } finally {
      $admin->close();
    }
  }

  /** Drop every ddd table for $prefix and apply the schema afresh. */
  public static function resetDddSchema(Connection $connection, string $prefix = ''): void {
    foreach (PostgresSchema::tables() as $table) {
      $connection->executeStatement('DROP TABLE IF EXISTS ' . $prefix . $table . ' CASCADE');
    }
    PostgresSchema::apply($connection, $prefix);
  }
}
