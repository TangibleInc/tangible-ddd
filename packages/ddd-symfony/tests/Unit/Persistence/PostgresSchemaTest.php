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
      self::assertMatchesRegularExpression('/^CREATE (TABLE|INDEX|UNIQUE INDEX)/', $statement);
    }
  }

  public function test_rejects_a_prefix_that_is_not_an_identifier(): void {
    $this->expectException(\InvalidArgumentException::class);
    PostgresSchema::render('x; DROP TABLE y; --');
  }
}
