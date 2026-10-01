<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Symfony\Persistence\PostgresSchema;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/** L5: the schema (every numbered file, ALTERs included) applies on Postgres 16 and re-applies as a no-op. */
final class PostgresSchemaApplyTest extends PostgresTestCase {

  public function test_the_whole_schema_applies_twice(): void {
    PostgresSchema::apply($this->db); // setUp applied it once already

    foreach (PostgresSchema::tables() as $table) {
      self::assertNotNull($this->db->fetchOne('SELECT to_regclass(?)', [$table]), "$table exists");
    }
  }

  public function test_the_since_output_applies_on_top_of_the_earlier_files(): void {
    foreach (PostgresSchema::tables() as $table) {
      $this->db->executeStatement("DROP TABLE IF EXISTS $table CASCADE");
    }
    $files = PostgresSchema::files();
    $previous = count($files) - 1;
    // The host migrated up to the previous file, then adds the `--since` output as its next migration.
    foreach (array_slice($files, 0, $previous) as $file) {
      $this->execute(str_replace('{{prefix}}', '', (string) file_get_contents($file)));
    }
    $this->execute(PostgresSchema::render('', $previous));

    foreach (PostgresSchema::tables() as $table) {
      self::assertNotNull($this->db->fetchOne('SELECT to_regclass(?)', [$table]), "$table exists");
    }
  }

  private function execute(string $sql): void {
    $lines = array_filter(explode("\n", $sql), static fn (string $l) => !str_starts_with(ltrim($l), '--'));
    foreach (explode(';', implode("\n", $lines)) as $statement) {
      if (trim($statement) !== '') {
        $this->db->executeStatement($statement);
      }
    }
  }
}
