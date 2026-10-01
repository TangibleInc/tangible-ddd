<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\PostgresSchema;

final class PostgresSchemaTest extends TestCase {

  public function test_files_are_the_relay_and_process_tables_in_order(): void {
    $names = array_map('basename', PostgresSchema::files());

    self::assertSame([
      '001_outbox.sql', '002_dlq.sql', '003_relay_pauses.sql', '004_delivery_ledger.sql',
      '005_processes.sql', '006_process_waits.sql', '007_wakeups.sql', '008_workflows.sql',
    ], $names);
    self::assertSame([
      'ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger',
      'ddd_processes', 'ddd_process_waits', 'ddd_wakeups',
      'ddd_behaviour_workflows', 'ddd_behaviour_workflow_meta', 'ddd_behaviour_workflow_items', 'ddd_workflow_ignitions',
    ], PostgresSchema::tables());
  }

  public function test_every_create_table_is_listed_in_tables(): void {
    preg_match_all('/CREATE TABLE IF NOT EXISTS \{\{prefix\}\}(\w+)/', implode("\n", array_map('file_get_contents', PostgresSchema::files())), $m);

    self::assertSame($m[1], PostgresSchema::tables());
  }

  public function test_the_process_table_gates_ignition_on_class_and_ignition_key(): void {
    $sql = PostgresSchema::render('');

    self::assertMatchesRegularExpression('/ddd_processes_ignition_key UNIQUE \(process_class, ignition_key\)/', $sql);
    self::assertStringContainsString('quarantine_reason', $sql);
    self::assertMatchesRegularExpression('/version\s+INTEGER\s+NOT NULL DEFAULT 1/', $sql);
  }

  public function test_render_substitutes_the_prefix_and_leaves_no_token(): void {
    $sql = PostgresSchema::render('app_');

    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS app_ddd_outbox', $sql);
    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS app_ddd_delivery_ledger', $sql);
    self::assertStringNotContainsString('{{prefix}}', $sql);
  }

  public function test_statements_split_into_executable_sql_without_comments(): void {
    $statements = PostgresSchema::statements('');

    self::assertNotEmpty($statements);
    foreach ($statements as $statement) {
      self::assertStringNotContainsString('--', $statement);
      self::assertMatchesRegularExpression('/^(CREATE (TABLE|INDEX|UNIQUE INDEX)|ALTER TABLE) /', $statement);
    }
  }

  // ── L5: schema evolution is append-only ───────────────────────────────────

  /**
   * The guard: a shipped file's statements never change. To change the schema,
   * add the next numbered file (ALTER TABLE ... IF NOT EXISTS, CREATE ... IF NOT
   * EXISTS) and append its line to schema/postgres/released.txt.
   */
  public function test_no_released_statement_file_has_changed(): void {
    $released = PostgresSchema::released();

    self::assertNotEmpty($released);
    foreach ($released as $file => $digest) {
      self::assertFileExists(PostgresSchema::dir() . '/' . $file, "released schema file $file was removed");
      self::assertSame($digest, PostgresSchema::digest(PostgresSchema::dir() . '/' . $file),
        "released schema file $file changed. Shipped statements are frozen: put the change in a new numbered file.");
    }
  }

  public function test_every_schema_file_is_in_the_released_list_in_file_order(): void {
    self::assertSame(
      array_map('basename', PostgresSchema::files()),
      array_keys(PostgresSchema::released()),
      'append the new file\'s line (PostgresSchema::digest) to schema/postgres/released.txt'
    );
  }

  public function test_files_are_numbered_contiguously_from_001(): void {
    foreach (array_map('basename', PostgresSchema::files()) as $i => $name) {
      self::assertMatchesRegularExpression('/^\d{3}_[a-z0-9_]+\.sql$/', $name);
      self::assertSame($i + 1, (int) substr($name, 0, 3), "$name is out of sequence");
    }
  }

  public function test_every_statement_is_idempotent_and_nothing_is_dropped(): void {
    foreach (PostgresSchema::statements('') as $sql) {
      $flat = preg_replace('/\s+/', ' ', $sql);
      self::assertDoesNotMatchRegularExpression('/\bDROP (TABLE|INDEX)\b/i', $flat);
      if (str_starts_with($flat, 'CREATE ')) {
        self::assertMatchesRegularExpression('/^CREATE (UNIQUE )?(TABLE|INDEX) IF NOT EXISTS /', $flat);
      } else {
        self::assertMatchesRegularExpression('/^ALTER TABLE (IF EXISTS )?\S+ (ADD COLUMN IF NOT EXISTS|DROP CONSTRAINT IF EXISTS|ALTER COLUMN) /', $flat);
      }
    }
  }

  public function test_the_digest_ignores_comments_and_layout_but_not_statements(): void {
    $dir = sys_get_temp_dir() . '/ddd-sf-digest-' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
      file_put_contents("$dir/a.sql", "-- one\nCREATE TABLE IF NOT EXISTS {{prefix}}t (id INT);\n");
      file_put_contents("$dir/b.sql", "-- another comment\nCREATE TABLE IF NOT EXISTS {{prefix}}t\n    (id INT);\n");
      file_put_contents("$dir/c.sql", "-- one\nCREATE TABLE IF NOT EXISTS {{prefix}}t (id BIGINT);\n");

      self::assertSame(PostgresSchema::digest("$dir/a.sql"), PostgresSchema::digest("$dir/b.sql"));
      self::assertNotSame(PostgresSchema::digest("$dir/a.sql"), PostgresSchema::digest("$dir/c.sql"));
    } finally {
      array_map('unlink', glob("$dir/*.sql"));
      rmdir($dir);
    }
  }

  public function test_render_since_emits_only_the_later_files_in_order(): void {
    $all = PostgresSchema::files();
    $last = (int) substr(basename(end($all)), 0, 3);

    self::assertSame('', PostgresSchema::render('', $last));
    $tail = PostgresSchema::render('', $last - 2);
    self::assertStringContainsString(basename($all[count($all) - 2]), $tail);
    self::assertStringContainsString(basename($all[count($all) - 1]), $tail);
    self::assertStringNotContainsString(basename($all[count($all) - 3]), $tail);
    self::assertLessThan(
      strpos($tail, basename($all[count($all) - 1])),
      strpos($tail, basename($all[count($all) - 2])),
      'files are emitted in number order'
    );
  }

  public function test_rejects_a_prefix_that_is_not_an_identifier(): void {
    $this->expectException(\InvalidArgumentException::class);
    PostgresSchema::render('x; DROP TABLE y; --');
  }
}
