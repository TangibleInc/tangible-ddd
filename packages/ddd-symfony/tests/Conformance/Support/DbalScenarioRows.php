<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Doctrine\DBAL\Connection;
use TangibleDDD\Conformance\ScenarioRows;

/** The scenario's domain table, on the ONE host connection (it commits or rolls back with the outbox row). */
final class DbalScenarioRows implements ScenarioRows {

  public const TABLE = 'conf_scenario_rows';

  public function __construct(private readonly Connection $connection) {}

  public static function createSql(): string {
    return 'CREATE TABLE ' . self::TABLE . ' (id TEXT PRIMARY KEY, value TEXT NOT NULL)';
  }

  public function insert(string $id, string $value): void {
    $this->connection->insert(self::TABLE, ['id' => $id, 'value' => $value]);
  }

  public function has(string $id): bool {
    return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ?', [$id]) !== false;
  }

  public function count(): int {
    return (int) $this->connection->fetchOne('SELECT count(*) FROM ' . self::TABLE);
  }
}
