<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The plain MySQL 8 schema files under `schema/mysql8/` with the table prefix
 * substituted, for the host to apply with its own tooling (register 0 item 1:
 * no migrator in core). This class only reads and prints the files; it never
 * executes them. SchemaCheck verifies the result.
 *
 * Every statement is `CREATE TABLE IF NOT EXISTS`, InnoDB, utf8mb4, DYNAMIC.
 */
final class SchemaSql {

  /** The logical tables, in file order. */
  public const TABLES = ['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger', 'ddd_processes', 'ddd_jobs'];

  public static function directory(): string {
    return dirname(__DIR__, 3) . '/schema/mysql8';
  }

  /** @return list<string> absolute paths, in apply order */
  public static function files(): array {
    $files = glob(self::directory() . '/*.sql') ?: [];
    sort($files);
    return array_values($files);
  }

  /**
   * One string per CREATE statement, prefix substituted, comments stripped.
   *
   * @return list<string>
   */
  public static function statements(string $tablePrefix = ''): array {
    new PrefixedTableNames($tablePrefix); // validates the prefix: it is interpolated, never bound
    $out = [];
    foreach (self::files() as $file) {
      $sql = (string) file_get_contents($file);
      $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
      foreach (explode(';', str_replace('{{prefix}}', $tablePrefix, $sql)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
          $out[] = $statement;
        }
      }
    }
    return $out;
  }

  /** The whole schema as one script, for a host's own migration file. */
  public static function dump(string $tablePrefix = ''): string {
    return implode(";\n\n", self::statements($tablePrefix)) . ";\n";
  }
}
