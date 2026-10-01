<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\PdoConfigurationError;
use TangibleDDD\Defaults\Pdo\SchemaCheck;
use TangibleDDD\Defaults\Pdo\SchemaSql;

abstract class SchemaCheckCases extends PdoTestCase {

  private const SCRATCH = 'sc_';

  protected function tearDown(): void {
    foreach (SchemaSql::TABLES as $t) {
      $this->db->execute('DROP TABLE IF EXISTS `' . self::SCRATCH . $t . '`');
    }
    parent::tearDown();
  }

  /** @return list<string> */
  private function tables(): array {
    return array_column($this->db->fetch_all(
      'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    ), 't');
  }

  public function test_the_schema_the_host_applied_passes(): void {
    $check = new SchemaCheck($this->db, self::PREFIX);
    self::assertSame([], $check->problems());
    $check->assert();
  }

  public function test_a_missing_schema_is_reported_and_never_created(): void {
    $before = $this->tables();
    $check = new SchemaCheck($this->db, self::SCRATCH);

    $problems = $check->problems();

    self::assertCount(count(SchemaSql::TABLES), $problems);
    self::assertStringContainsString('sc_ddd_outbox is missing', $problems[0]);
    self::assertSame($before, $this->tables(), 'SchemaCheck never creates tables');

    try {
      $check->assert();
      self::fail('expected PdoConfigurationError');
    } catch (PdoConfigurationError $e) {
      self::assertStringContainsString('schema/mysql8', $e->getMessage());
      self::assertStringContainsString('SchemaSql::dump', $e->getMessage());
    }
  }

  public function test_missing_columns_unique_keys_and_a_wrong_engine_are_reported(): void {
    foreach (SchemaSql::statements(self::SCRATCH) as $statement) {
      $this->db->execute($statement);
    }
    $this->db->execute('ALTER TABLE `sc_ddd_processes` DROP COLUMN `quarantine_reason`');
    $this->db->execute('ALTER TABLE `sc_ddd_processes` DROP INDEX `uniq_ignition`');
    $this->db->execute('ALTER TABLE `sc_ddd_outbox` DROP COLUMN `claim_token`');
    $this->db->execute('ALTER TABLE `sc_ddd_dlq` ENGINE = MyISAM');

    $problems = (new SchemaCheck($this->db, self::SCRATCH))->problems();

    self::assertContains('sc_ddd_outbox: column claim_token is missing', $problems);
    self::assertContains('sc_ddd_processes: column quarantine_reason is missing', $problems);
    self::assertContains('sc_ddd_processes: unique key (process_class, ignition_key) is missing', $problems);
    self::assertContains('sc_ddd_dlq: engine is MyISAM, expected InnoDB', $problems);
    self::assertCount(4, $problems);
  }

  public function test_the_dump_is_the_files_with_the_prefix_substituted(): void {
    $dump = SchemaSql::dump('acme_');

    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `acme_ddd_outbox`', $dump);
    self::assertStringNotContainsString('{{prefix}}', $dump);
    foreach (SchemaSql::statements('acme_') as $statement) {
      self::assertMatchesRegularExpression('/^CREATE TABLE IF NOT EXISTS `acme_ddd_[a-z_]+` \(.*\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC$/s', $statement);
    }
    self::assertCount(count(SchemaSql::TABLES), SchemaSql::statements());

    $this->expectException(\InvalidArgumentException::class);
    SchemaSql::statements('bad prefix;');
  }
}
