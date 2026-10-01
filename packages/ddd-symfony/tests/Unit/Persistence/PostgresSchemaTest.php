<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\PostgresSchema;

final class PostgresSchemaTest extends TestCase {

  public function test_files_are_the_four_round_one_tables_in_order(): void {
    $names = array_map('basename', PostgresSchema::files());

    self::assertSame(['001_outbox.sql', '002_dlq.sql', '003_relay_pauses.sql', '004_delivery_ledger.sql'], $names);
    self::assertSame(['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger'], PostgresSchema::tables());
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
      self::assertMatchesRegularExpression('/^CREATE (TABLE|INDEX)/', $statement);
    }
  }

  public function test_rejects_a_prefix_that_is_not_an_identifier(): void {
    $this->expectException(\InvalidArgumentException::class);
    PostgresSchema::render('x; DROP TABLE y; --');
  }
}
