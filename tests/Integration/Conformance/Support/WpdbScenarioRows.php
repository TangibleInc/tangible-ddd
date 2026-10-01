<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Conformance\ScenarioRows;

/**
 * The scenario's domain table on the WordPress connection (InnoDB, the
 * consumer's per-test prefix), so a rollback of the boundary removes the
 * domain row together with the outbox row.
 */
final class WpdbScenarioRows implements ScenarioRows {

  public function __construct(public readonly string $table) {}

  public function create(): void {
    $this->db()->query("CREATE TABLE IF NOT EXISTS `{$this->table}` (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($this->db()->last_error !== '') {
      throw new \RuntimeException("Cannot create {$this->table}: {$this->db()->last_error}");
    }
  }

  public function insert(string $id, string $value): void {
    if ($this->db()->insert($this->table, ['id' => $id, 'value' => $value]) === false) {
      throw new \RuntimeException("Scenario row $id not inserted: {$this->db()->last_error}");
    }
  }

  public function has(string $id): bool {
    $db = $this->db();
    return $db->get_var($db->prepare("SELECT 1 FROM `{$this->table}` WHERE id = %s", $id)) !== null;
  }

  public function count(): int {
    return (int) $this->db()->get_var("SELECT COUNT(*) FROM `{$this->table}`");
  }

  private function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
