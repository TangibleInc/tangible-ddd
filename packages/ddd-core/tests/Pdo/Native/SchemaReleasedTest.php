<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Defaults\Pdo\SchemaSql;

/**
 * Append-only schema evolution for schema/mysql8 (wave-3 TXP demand L5, the
 * same rule ddd-symfony gates with its released.txt): a shipped file never
 * changes; a change is the next numbered file plus its line in
 * schema/mysql8/released.txt. Comments and whitespace may change, the
 * statements may not. No database needed (runs once, not per prepare mode).
 */
final class SchemaReleasedTest extends TestCase {

  public function test_every_shipped_file_is_listed_in_order_with_an_unchanged_digest(): void {
    $released = SchemaSql::released();
    $files = array_map('basename', SchemaSql::files());

    self::assertSame($files, array_keys($released), 'every schema file has a released.txt line, in apply order (append the next one)');
    foreach ($released as $file => $digest) {
      self::assertSame($digest, SchemaSql::digest(SchemaSql::directory() . '/' . $file), "$file changed after it shipped: add a new numbered file instead");
    }
  }

  public function test_files_are_numbered_contiguously(): void {
    foreach (array_values(array_map('basename', SchemaSql::files())) as $i => $file) {
      self::assertMatchesRegularExpression(sprintf('/^%03d_[a-z0-9_]+\.sql$/', $i + 1), $file);
    }
  }

  public function test_every_statement_is_an_idempotent_create_table(): void {
    foreach (SchemaSql::statements('x_') as $statement) {
      self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `x_', $statement, 'MySQL 8 has no ADD COLUMN IF NOT EXISTS: new state goes in a new table');
    }
  }

  public function test_the_digest_ignores_comments_and_whitespace_but_not_statements(): void {
    $dir = sys_get_temp_dir() . '/ddd-schema-' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
      file_put_contents("$dir/a.sql", "-- one\nCREATE TABLE IF NOT EXISTS `{{prefix}}t` (\n  `id` INT\n);\n");
      file_put_contents("$dir/b.sql", "-- two, longer\n\nCREATE TABLE IF NOT EXISTS `{{prefix}}t` (`id` INT);");
      file_put_contents("$dir/c.sql", "CREATE TABLE IF NOT EXISTS `{{prefix}}t` (`id` BIGINT);");

      self::assertSame(SchemaSql::digest("$dir/a.sql"), SchemaSql::digest("$dir/b.sql"));
      self::assertNotSame(SchemaSql::digest("$dir/a.sql"), SchemaSql::digest("$dir/c.sql"));
    } finally {
      array_map('unlink', glob("$dir/*") ?: []);
      rmdir($dir);
    }
  }

  public function test_statements_since_a_file_number_are_the_hosts_next_migration(): void {
    $all = SchemaSql::statements('x_');
    $since6 = SchemaSql::statements('x_', 6);

    self::assertNotSame([], $since6);
    self::assertSame(array_slice($all, count($all) - count($since6)), $since6);
    foreach ($since6 as $statement) {
      self::assertStringNotContainsString('`x_ddd_jobs`', $statement, '006 and earlier are left out');
    }
    self::assertSame($all, SchemaSql::statements('x_', 0));
    self::assertStringContainsString('x_ddd_process_waits', SchemaSql::dump('x_', 6));
  }
}
