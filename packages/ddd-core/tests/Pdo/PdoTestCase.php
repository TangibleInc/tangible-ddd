<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\SchemaSql;

/**
 * Base of every Defaults/Pdo adapter case. Concrete classes live in
 * tests/Pdo/Native (ATTR_EMULATE_PREPARES = false) and tests/Pdo/Emulated
 * (true) and only choose the mode, so each behaviour runs in both.
 *
 * This is the only place in the package that constructs a PDO: the adapters
 * themselves never do (register 1.2).
 */
abstract class PdoTestCase extends TestCase {

  public const PREFIX = 'tp_';

  /** @var array<string, \PDO> one shared PDO per prepare mode */
  private static array $shared = [];

  protected PdoConnection $db;

  abstract protected static function emulatePrepares(): bool;

  /** Drop and recreate this suite's own database, then apply the schema as a host would. */
  public static function prepareDatabase(): void {
    $name = self::databaseName();
    $server = self::connect(null, false);
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $pdo = self::connect($name, false);
    foreach (SchemaSql::statements(self::PREFIX) as $statement) {
      $pdo->exec($statement);
    }
  }

  public static function databaseName(): string {
    $name = getenv('DDD_PDO_DATABASE') ?: 'ddd_w3_pdo_adapters';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
      throw new \InvalidArgumentException("DDD_PDO_DATABASE '$name' must match [A-Za-z0-9_]+");
    }
    return $name;
  }

  /** A fresh PDO on the test database (a second "worker" or "process"). */
  public static function newPdo(bool $emulate, array $options = []): \PDO {
    return self::connect(self::databaseName(), $emulate, $options);
  }

  private static function connect(?string $database, bool $emulate, array $options = []): \PDO {
    $host = getenv('DDD_PDO_HOST') ?: '127.0.0.1';
    $port = getenv('DDD_PDO_PORT') ?: '33306';
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4" . ($database === null ? '' : ";dbname=$database");
    return new \PDO($dsn, getenv('DDD_PDO_USER') ?: 'root', getenv('DDD_PDO_PASSWORD') ?: 'ddd', $options + [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_EMULATE_PREPARES => $emulate,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
  }

  protected function pdo(): \PDO {
    $mode = static::emulatePrepares() ? 'emulated' : 'native';
    return self::$shared[$mode] ??= self::newPdo(static::emulatePrepares());
  }

  /** A second, independent connection in the same prepare mode. */
  protected function otherConnection(): PdoConnection {
    return new PdoConnection(self::newPdo(static::emulatePrepares()));
  }

  protected function table(string $logical): string {
    return self::PREFIX . $logical;
  }

  protected function setUp(): void {
    $pdo = $this->pdo();
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    self::assertSame(static::emulatePrepares(), (bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    foreach (SchemaSql::TABLES as $logical) {
      $pdo->exec('DELETE FROM `' . $this->table($logical) . '`');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tp_widgets` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(64) NOT NULL UNIQUE, `owner_id` INT NULL, CONSTRAINT `tp_widgets_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `tp_widgets` (`id`)) ENGINE=InnoDB");
    $pdo->exec('DELETE FROM `tp_widgets`');
    $this->db = new PdoConnection($pdo);
  }

  protected static function utc(string $time): \DateTimeImmutable {
    return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
  }

  /** @return array<string, mixed>|null */
  protected function row(string $logical, string $where, array $params = []): ?array {
    return $this->db->fetch_one('SELECT * FROM `' . $this->table($logical) . "` WHERE $where", $params);
  }

  protected function countRows(string $logical, string $where = '1 = 1', array $params = []): int {
    return (int) ($this->db->fetch_one('SELECT COUNT(*) AS n FROM `' . $this->table($logical) . "` WHERE $where", $params)['n'] ?? 0);
  }
}
