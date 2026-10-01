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
  public const TABLES = ['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger', 'ddd_processes', 'ddd_jobs', 'ddd_process_waits'];

  public static function directory(): string {
    return dirname(__DIR__, 3) . '/schema/mysql8';
  }

  /** @return list<string> absolute paths, in apply order */
  public static function files(): array {
    $files = glob(self::directory() . '/*.sql') ?: [];
    sort($files);
    return $files;
  }

  /**
   * One string per CREATE statement, prefix substituted, comments stripped.
   *
   * $since (wave 4, L5): only the files numbered above it (`NNN_*.sql`), i.e.
   * what a host that applied files up to $since still has to apply. Null or
   * 0 = every file.
   *
   * @return list<string>
   */
  public static function statements(string $tablePrefix = '', ?int $since = null): array {
    new PrefixedTableNames($tablePrefix); // validates the prefix: it is interpolated, never bound
    $out = [];
    foreach (self::files() as $file) {
      if ($since !== null && self::numberOf($file) <= $since) {
        continue;
      }
      foreach (self::fileStatements($file, $tablePrefix) as $statement) {
        $out[] = $statement;
      }
    }
    return $out;
  }

  /** The whole schema (or the files after $since) as one script, for a host's own migration file. */
  public static function dump(string $tablePrefix = '', ?int $since = null): string {
    return implode(";\n\n", self::statements($tablePrefix, $since)) . ";\n";
  }

  /**
   * The shipped files and their digests, from `schema/mysql8/released.txt`
   * (append-only schema evolution, L5): a shipped file never changes; a
   * schema change is the next numbered file plus its line there.
   *
   * @return array<string, string> file name => digest, in apply order
   */
  public static function released(): array {
    $out = [];
    foreach (file(self::directory() . '/released.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      [$file, $digest] = preg_split('/\s+/', $line) + [1 => ''];
      $out[$file] = $digest;
    }
    return $out;
  }

  /**
   * sha256 of a file's statements with comments removed and whitespace
   * normalised, so editing a comment keeps the digest and editing a
   * statement does not.
   */
  public static function digest(string $path): string {
    $normalised = array_map(
      static fn (string $s) => preg_replace('/\s*([(),])\s*/', '$1', preg_replace('/\s+/', ' ', $s) ?? $s) ?? $s,
      self::fileStatements($path, '{{prefix}}')
    );
    return hash('sha256', implode(";\n", $normalised));
  }

  /** @return list<string> */
  private static function fileStatements(string $file, string $tablePrefix): array {
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $out = [];
    foreach (explode(';', str_replace('{{prefix}}', $tablePrefix, $sql)) as $statement) {
      $statement = trim($statement);
      if ($statement !== '') {
        $out[] = $statement;
      }
    }
    return $out;
  }

  private static function numberOf(string $file): int {
    return preg_match('/^(\d+)_/', basename($file), $m) ? (int) $m[1] : 0;
  }
}
