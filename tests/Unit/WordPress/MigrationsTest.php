<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

use function TangibleDDD\WordPress\ddd_explicit_migrations;
use function TangibleDDD\WordPress\ddd_pending_migrations;

use const TangibleDDD\WordPress\DDD_SCHEMA_VERSION;

// migrations.php is a procedural ddd-wordpress file; load it directly for the
// pure-logic test (no WP/DB needed for ddd_pending_migrations).
if (!function_exists('TangibleDDD\\WordPress\\ddd_pending_migrations')) {
  require_once __DIR__ . '/../../../packages/ddd-wp/wordpress/migrations.php';
}

/**
 * The version gate's pure core: given the installed schema version and the
 * current one, which versions must run, in order. Everything else in the
 * migrator is WP/DB-bound (get_option / dbDelta / $wpdb) and verified live.
 */
class MigrationsTest extends TestCase {

  public function test_fresh_install_pends_every_version_to_current(): void {
    $this->assertSame([1, 2], ddd_pending_migrations(0, 2));
  }

  public function test_up_to_date_pends_nothing(): void {
    $this->assertSame([], ddd_pending_migrations(2, 2));
  }

  public function test_one_behind_pends_only_the_new_version(): void {
    $this->assertSame([2], ddd_pending_migrations(1, 2));
  }

  public function test_a_multi_version_gap_pends_in_ascending_order(): void {
    $this->assertSame([2, 3, 4, 5], ddd_pending_migrations(1, 5));
  }

  public function test_installed_ahead_never_runs_backward(): void {
    // Downgrade / weird state must not produce negative or backward work.
    $this->assertSame([], ddd_pending_migrations(3, 2));
  }

  public function test_current_schema_version_is_8(): void {
    // Regression guard for the v3-fast-path bug lineage: bumping the schema
    // (v8 = the wave-3 durable contracts) must move this constant, or
    // consumers' fast-paths treat themselves as current and never create
    // the tables.
    $this->assertSame(8, DDD_SCHEMA_VERSION);
  }

  public function test_v8_migration_has_an_explicit_entry(): void {
    $migrations = ddd_explicit_migrations();
    $this->assertArrayHasKey(8, $migrations, 'consumers already at v7 skip dbDelta on the fast path — the explicit entry creates the v8 tables, columns and backfills for them.');
  }

  public function test_v8_adds_only_nullable_or_defaulted_columns(): void {
    // R5: a rolled-back 0.6 winner inserts rows naming none of the v8
    // columns, so every one must be NULL-able or defaulted.
    $spy = new class extends \wpdb {
      public array $queries = [];
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return 0;
      }
      public function query(string $query) {
        $this->queries[] = $query;
        return true;
      }
    };
    $GLOBALS['wpdb'] = $spy;

    foreach ([
      ['wp_test_integration_outbox', 'claim_token', 'VARCHAR(64) NULL'],
      ['wp_test_long_processes', 'version', 'INT UNSIGNED NOT NULL DEFAULT 1'],
      ['wp_test_long_processes', 'ignition_key', 'CHAR(36) NULL'],
      ['wp_test_long_processes', 'quarantine_reason', 'TEXT NULL'],
      ['wp_test_long_processes', 'start_path', 'VARCHAR(16) NULL'],
    ] as [$table, $column, $definition]) {
      \TangibleDDD\WordPress\ddd_add_column_if_missing($table, $column, $definition);
    }
    \TangibleDDD\WordPress\ddd_add_unique_index_if_missing('wp_test_long_processes', 'uniq_ignition_key', '`process_class`, `ignition_key`');

    foreach (array_slice($spy->queries, 0, 5) as $sql) {
      $this->assertMatchesRegularExpression('/ADD COLUMN `\w+` [A-Z0-9() ]+ (NULL|NOT NULL DEFAULT \d+)$/', $sql);
    }
    $this->assertSame('ALTER TABLE `wp_test_long_processes` ADD UNIQUE KEY `uniq_ignition_key` (`process_class`, `ignition_key`)', $spy->queries[5]);
  }

  public function test_v6_migration_installs_the_touches_table(): void {
    $migrations = ddd_explicit_migrations();
    $this->assertArrayHasKey(6, $migrations, 'consumers already at v5 skip dbDelta on the fast path — the explicit entry creates the touches table for them.');
  }

  public function test_v7_migration_installs_the_workflow_meta_table_and_backfills(): void {
    $migrations = ddd_explicit_migrations();
    $this->assertArrayHasKey(7, $migrations, 'consumers already at v6 skip dbDelta on the fast path — the explicit entry creates behaviour_workflows_meta and pivots the JSON meta column into rows.');
  }

  public function test_v4_migration_adds_await_mechanism_after_match_criteria_on_long_processes(): void {
    $migrations = ddd_explicit_migrations();
    $this->assertArrayHasKey(4, $migrations, 'ddd_explicit_migrations() must define a v4 entry so consumers already at v3 actually get the column.');

    // Spy wpdb: report the column as absent (get_var => 0) so the guarded
    // helper proceeds to the ALTER, and capture the SQL it issues.
    $spy = new class extends \wpdb {
      public array $queries = [];
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return 0;
      }
      public function query(string $query) {
        $this->queries[] = $query;
        return true;
      }
    };
    $GLOBALS['wpdb'] = $spy;

    $migrations[4](new FakeDDDConfig());

    $this->assertCount(1, $spy->queries);
    $this->assertStringContainsString('wp_test_long_processes', $spy->queries[0]);
    $this->assertStringContainsString('ADD COLUMN `await_mechanism` JSON NULL', $spy->queries[0]);
    $this->assertStringContainsString('AFTER `match_criteria`', $spy->queries[0]);
  }
}
