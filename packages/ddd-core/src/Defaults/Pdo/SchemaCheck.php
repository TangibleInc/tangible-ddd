<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * Verifies that the host applied `schema/mysql8/*.sql` with the given
 * prefix (register 0 item 1, 1.2). It reads information_schema for the
 * connection's current database and NEVER creates, alters or migrates
 * anything: applying the files is the host's job (SchemaSql::dump() prints
 * them).
 *
 * Checked per table: it exists, it is InnoDB, every column of the file is
 * present, and every PRIMARY / UNIQUE key of the file exists with the same
 * columns (the unique keys carry correctness: uniq_event_id,
 * uniq_ignition, uniq_idempotency_key). Extra host columns or indexes are
 * allowed.
 */
final class SchemaCheck {

  public function __construct(private readonly IHostConnection $db, private readonly string $tablePrefix = '') {
    new PrefixedTableNames($tablePrefix);
  }

  /** @return list<string> human-readable problems; empty when the schema is in place */
  public function problems(): array {
    $problems = [];
    foreach (self::expected($this->tablePrefix) as $table => $spec) {
      $info = $this->db->fetchOne(
        'SELECT engine AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
      );
      if ($info === null) {
        $problems[] = "$table is missing";
        continue;
      }
      if (strcasecmp((string) $info['engine'], 'InnoDB') !== 0) {
        $problems[] = "$table: engine is {$info['engine']}, expected InnoDB";
      }

      $columns = array_map('strval', array_column($this->db->fetchAll(
        'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
      ), 'c'));
      foreach ($spec['columns'] as $column) {
        if (!in_array($column, $columns, true)) {
          $problems[] = "$table: column $column is missing";
        }
      }

      $unique = [];
      foreach ($this->db->fetchAll(
        'SELECT index_name AS i, column_name AS c FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0
         ORDER BY index_name, seq_in_index',
        [$table]
      ) as $row) {
        $unique[(string) $row['i']][] = (string) $row['c'];
      }
      foreach ($spec['unique'] as [$kind, $keyColumns]) {
        if (!in_array($keyColumns, array_values($unique), true)) {
          $problems[] = sprintf('%s: %s (%s) is missing', $table, $kind, implode(', ', $keyColumns));
        }
      }
    }
    return $problems;
  }

  /** @throws PdoConfigurationError listing every problem */
  public function assert(): void {
    $problems = $this->problems();
    if ($problems !== []) {
      throw new PdoConfigurationError(
        "The TangibleDDD\\Defaults\\Pdo schema is not in place:\n  - " . implode("\n  - ", $problems)
        . "\nApply packages/ddd-core/schema/mysql8/*.sql with your own tooling "
        . "(SchemaSql::dump('{$this->tablePrefix}') prints them with the prefix substituted)."
      );
    }
  }

  /** @return array<string, array{columns: list<string>, unique: list<array{0: string, 1: list<string>}>}> */
  private static function expected(string $prefix): array {
    $out = [];
    foreach (SchemaSql::statements($prefix) as $statement) {
      if (!preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $statement, $m)) {
        continue;
      }
      preg_match_all('/^\s*`([a-z0-9_]+)`\s+[A-Z]/m', $statement, $columns);
      $unique = [];
      if (preg_match('/PRIMARY KEY \(([^)]+)\)/', $statement, $pk)) {
        $unique[] = ['primary key', self::columnList($pk[1])];
      }
      preg_match_all('/UNIQUE KEY `[^`]+` \(([^)]+)\)/', $statement, $uks);
      foreach ($uks[1] as $list) {
        $unique[] = ['unique key', self::columnList($list)];
      }
      $out[$m[1]] = ['columns' => $columns[1], 'unique' => $unique];
    }
    return $out;
  }

  /** @return list<string> */
  private static function columnList(string $list): array {
    return array_map(static fn (string $c) => trim($c, " `\t\n"), explode(',', $list));
  }
}
