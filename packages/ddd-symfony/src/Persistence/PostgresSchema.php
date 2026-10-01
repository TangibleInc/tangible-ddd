<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;

/**
 * The plain SQL schema under packages/ddd-symfony/schema/postgres (X9: no
 * migration framework). The host copies `ddd:schema:dump` output into its own
 * migrations; tests apply it directly.
 *
 * Every file is idempotent (CREATE ... IF NOT EXISTS, ALTER TABLE ... ADD
 * COLUMN IF NOT EXISTS). `{{prefix}}` is the configured table prefix. Wave 2
 * shipped the outbox, DLQ, relay pauses and the delivery ledger; wave 3 adds
 * processes, process waits, wakeup intents and the D10 workflow tables
 * (workflows, meta, items, ignition ledger); wave 4 adds the D1 effect
 * journal.
 *
 * Evolution is append-only (L5). A file listed in `released.txt` has shipped:
 * its statements never change (the digest ignores comments and whitespace,
 * and a unit test compares it). A schema change is the next numbered file
 * (`NNN_what.sql`, contiguous), whose statements are idempotent ALTER / CREATE
 * statements, plus its line in `released.txt`. render() emits the files in
 * number order; `ddd:schema:dump --since=NNN` emits only the files after
 * NNN, for the host's next migration.
 *
 * The Messenger Doctrine transport table (messenger_messages) is NOT here:
 * create it with `messenger:setup-transports`. It must exist before the relay
 * runs, because the relay submits inside a transaction, where Messenger's
 * auto-setup cannot run.
 */
final class PostgresSchema {

  /** @return list<string> absolute paths, in apply order */
  public static function files(): array {
    $files = glob(self::dir() . '/*.sql') ?: [];
    sort($files);
    return array_values($files);
  }

  /** @return list<string> logical table names (unprefixed), in apply order */
  public static function tables(): array {
    return [
      'ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger',
      'ddd_processes', 'ddd_process_waits', 'ddd_wakeups',
      'ddd_behaviour_workflows', 'ddd_behaviour_workflow_meta', 'ddd_behaviour_workflow_items', 'ddd_workflow_ignitions',
      'ddd_effect_journal',
    ];
  }

  /**
   * The schema DDL, file by file in number order, each headed by a comment
   * naming its file. $since = N emits only the files numbered above N;
   * $until = M only those up to M (a host's history at release M).
   *
   * $prefix is `[schema.]prefix` (TableNames): with a schema, the output
   * starts with `CREATE SCHEMA IF NOT EXISTS`, tables are schema-qualified,
   * index and constraint names are not.
   */
  public static function render(string $prefix = '', ?int $since = null, ?int $until = null): string {
    $names = self::names($prefix);
    $out = [];
    foreach (self::files() as $file) {
      if (($since !== null && self::number($file) <= $since) || ($until !== null && self::number($file) > $until)) {
        continue;
      }
      $out[] = '-- tangible/ddd-symfony schema/postgres/' . basename($file) . "\n"
        . self::substitute((string) file_get_contents($file), $names);
    }
    if ($out === []) {
      return '';
    }
    // A consumer in its own Postgres schema (wave 5): the schema comes first.
    $schema = $names->schema() === null ? '' : "CREATE SCHEMA IF NOT EXISTS {$names->schema()};\n\n";
    return $schema . implode("\n", $out);
  }

  /**
   * `{{prefix}}` → `[schema.]prefix` for table references, but only the bare
   * prefix where Postgres takes an unqualified name: an index name (it lives
   * in its table's schema) and a constraint name.
   */
  private static function substitute(string $sql, TableNames $names): string {
    $sql = preg_replace('/\b(CONSTRAINT|INDEX IF NOT EXISTS|INDEX) \{\{prefix\}\}/', '$1 ' . $names->prefix(), $sql) ?? $sql;
    return str_replace('{{prefix}}', $names->qualified(), $sql);
  }

  /** @return list<string> executable statements, comments stripped */
  public static function statements(string $prefix = '', ?int $since = null, ?int $until = null): array {
    return self::split(self::render($prefix, $since, $until));
  }

  /** The number of the newest schema file (the head of the append-only history). */
  public static function head(): int {
    $files = self::files();
    return $files === [] ? 0 : self::number(end($files));
  }

  /**
   * The released files and the digests of their statements, from
   * schema/postgres/released.txt (`<file> <sha256>` per line).
   *
   * @return array<string, string> file name => digest, in file order
   */
  public static function released(): array {
    $released = [];
    $manifest = self::dir() . '/released.txt';
    $lines = is_file($manifest) ? file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    foreach ($lines ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      [$file, $digest] = preg_split('/\s+/', $line, 2) + [1 => ''];
      $released[$file] = $digest;
    }
    return $released;
  }

  /** sha256 of a schema file's statements, independent of comments and whitespace. */
  public static function digest(string $path): string {
    $statements = array_map(
      static fn (string $sql) => preg_replace('/\s+/', ' ', $sql),
      self::split((string) file_get_contents($path))
    );
    return hash('sha256', implode(";\n", $statements));
  }

  public static function apply(Connection $connection, string $prefix = '', ?int $since = null, ?int $until = null): void {
    foreach (self::statements($prefix, $since, $until) as $sql) {
      $connection->executeStatement($sql);
    }
  }

  public static function dir(): string {
    return dirname(__DIR__, 2) . '/schema/postgres';
  }

  private static function number(string $file): int {
    return (int) substr(basename($file), 0, 3);
  }

  /** @return list<string> */
  private static function split(string $sql): array {
    $lines = array_filter(
      explode("\n", $sql),
      static fn (string $line) => !str_starts_with(ltrim($line), '--')
    );
    $statements = [];
    foreach (explode(';', implode("\n", $lines)) as $chunk) {
      $statement = trim($chunk);
      if ($statement !== '') {
        $statements[] = $statement;
      }
    }
    return $statements;
  }

  /** `[schema.]prefix`; the prefix part is lower case here (it names Postgres objects). */
  private static function names(string $prefix): TableNames {
    $names = TableNames::of($prefix);
    if (!preg_match('/^[a-z0-9_]*$/', $names->prefix())) {
      throw new \InvalidArgumentException("Table prefix '{$names->prefix()}' must match [a-z0-9_]*");
    }
    return $names;
  }
}
