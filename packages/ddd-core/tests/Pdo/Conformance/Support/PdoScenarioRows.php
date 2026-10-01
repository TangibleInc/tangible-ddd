<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Defaults\Pdo\IHostConnection;

/**
 * The scenario's domain table on the host connection: `{prefix}scenario_rows`
 * (id primary key, value), written inside whatever transaction is open, so
 * a rollback removes it together with the outbox row.
 */
final class PdoScenarioRows implements ScenarioRows {

  private readonly string $table;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix) {
    $this->table = $tablePrefix . 'scenario_rows';
  }

  public static function createSql(string $tablePrefix): string {
    return "CREATE TABLE IF NOT EXISTS `{$tablePrefix}scenario_rows` (
      `id` VARCHAR(191) NOT NULL PRIMARY KEY,
      `value` VARCHAR(191) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
  }

  public function insert(string $id, string $value): void {
    $this->db->execute("INSERT INTO `{$this->table}` (id, value) VALUES (?, ?)", [$id, $value]);
  }

  public function has(string $id): bool {
    return $this->db->fetchOne("SELECT 1 AS present FROM `{$this->table}` WHERE id = ?", [$id]) !== null;
  }

  public function count(): int {
    return (int) ($this->db->fetchOne("SELECT COUNT(*) AS n FROM `{$this->table}`")['n'] ?? 0);
  }
}
