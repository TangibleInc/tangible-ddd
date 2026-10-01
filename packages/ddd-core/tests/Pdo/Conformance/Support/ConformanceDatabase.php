<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

/**
 * The MySQL 8 server the pdo conformance host runs against, and the only
 * place in this suite that constructs a PDO (the adapters never do). Each
 * test gets a database of its own: create() makes it, drop() kills every
 * connection the test opened on it and drops it.
 *
 * Environment (shared with tests/Pdo): DDD_PDO_HOST (127.0.0.1),
 * DDD_PDO_PORT (33306), DDD_PDO_USER (root), DDD_PDO_PASSWORD (ddd).
 */
final class ConformanceDatabase {

  /** @return array{host: string, port: string, user: string, password: string} */
  public static function server(): array {
    return [
      'host' => getenv('DDD_PDO_HOST') ?: '127.0.0.1',
      'port' => getenv('DDD_PDO_PORT') ?: '33306',
      'user' => getenv('DDD_PDO_USER') ?: 'root',
      'password' => getenv('DDD_PDO_PASSWORD') ?: 'ddd',
    ];
  }

  public static function assertReachable(): void {
    try {
      self::connect(null, false)->query('SELECT 1');
    } catch (\PDOException $e) {
      $s = self::server();
      fwrite(STDERR, "pdo conformance: MySQL at {$s['host']}:{$s['port']} is not reachable: {$e->getMessage()}\n");
      exit(1);
    }
  }

  public static function create(string $name): void {
    self::assertName($name);
    $admin = self::connect(null, false);
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
  }

  /**
   * Kill the given server connections (so no open transaction or metadata
   * lock can block the DROP, and no PDO a test kept alive holds a server
   * slot), then drop the database. Never throws.
   *
   * @param list<int> $connectionIds
   */
  public static function drop(string $name, array $connectionIds): void {
    try {
      self::assertName($name);
      $admin = self::connect(null, false);
      foreach ($connectionIds as $id) {
        try {
          $admin->exec('KILL ' . (int) $id);
        } catch (\PDOException) {
          // already gone
        }
      }
      $admin->exec("DROP DATABASE IF EXISTS `$name`");
    } catch (\Throwable $e) {
      fwrite(STDERR, "pdo conformance: could not drop $name: {$e->getMessage()}\n");
    }
  }

  /** A new connection (a new server session) on $database. */
  public static function connect(?string $database, bool $emulatePrepares): \PDO {
    $s = self::server();
    $dsn = "mysql:host={$s['host']};port={$s['port']};charset=utf8mb4" . ($database === null ? '' : ";dbname=$database");
    return new \PDO($dsn, $s['user'], $s['password'], [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
  }

  public static function connectionId(\PDO $pdo): int {
    return (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
  }

  private static function assertName(string $name): void {
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
      throw new \InvalidArgumentException("Database name '$name' must match [a-z0-9_]{1,64}");
    }
  }
}
