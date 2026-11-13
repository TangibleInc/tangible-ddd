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

  public function test_current_schema_version_is_10(): void {
    // Regression guard for the v3-fast-path bug lineage: bumping the schema
    // (v8 = 0.6.13's command_audit.intents, v9 = the wave-3 durable
    // contracts, v10 = the wave-5 parked-fact column) must move this
    // constant, or consumers' fast-paths treat themselves as current and
    // never create the tables.
    $this->assertSame(10, DDD_SCHEMA_VERSION);
  }

  public function test_a_0_6_6_consumer_runs_8_9_10_in_order_and_each_has_an_entry(): void {
    $migrations = ddd_explicit_migrations();
    $pending = ddd_pending_migrations(7, DDD_SCHEMA_VERSION);
    $this->assertSame([8, 9, 10], $pending);
    foreach ($pending as $version) {
      $this->assertArrayHasKey($version, $migrations, "v$version must have an explicit entry: consumers at v7 skip dbDelta on the fast path.");
    }
  }

  public function test_a_0_6_13_consumer_runs_9_and_10_only(): void {
    // 0.6.13 stores schema 8 (command_audit.intents). Durable contracts must
    // sit above 8, or a 0.6.13 site would skip their tables and backfills.
    $this->assertSame([9, 10], ddd_pending_migrations(8, DDD_SCHEMA_VERSION));
  }

  public function test_v8_migration_adds_intents_after_environment_on_command_audit(): void {
    $spy = $this->spyWpdb(column_exists: false);

    ddd_explicit_migrations()[8](new FakeDDDConfig());

    $this->assertCount(1, $spy->queries);
    $this->assertStringContainsString('wp_test_command_audit', $spy->queries[0]);
    $this->assertStringContainsString('ADD COLUMN `intents` JSON NULL', $spy->queries[0]);
    $this->assertStringContainsString('AFTER `environment`', $spy->queries[0]);
  }

  public function test_v8_migration_is_a_no_op_when_intents_exists(): void {
    // A 0.6.13 site, or a fresh install whose dbDelta already made the column.
    $spy = $this->spyWpdb(column_exists: true);

    ddd_explicit_migrations()[8](new FakeDDDConfig());

    $this->assertSame([], $spy->queries);
  }

  public function test_v9_migration_runs_the_durable_contracts(): void {
    $migrations = ddd_explicit_migrations();
    $this->assertArrayHasKey(9, $migrations, 'consumers already at v8 skip dbDelta on the fast path — the explicit entry creates the v9 tables, columns and backfills for them.');
    $this->assertTrue(function_exists('TangibleDDD\\WordPress\\ddd_migrate_v9'));
  }

  public function test_v10_migration_adds_the_fact_column_after_args_on_ddd_wakeups(): void {
    $spy = $this->spyWpdb(column_exists: false);

    ddd_explicit_migrations()[10](new FakeDDDConfig());

    $this->assertCount(1, $spy->queries);
    $this->assertStringContainsString('wp_test_ddd_wakeups', $spy->queries[0]);
    $this->assertStringContainsString('ADD COLUMN `fact` LONGTEXT NULL', $spy->queries[0]);
    $this->assertStringContainsString('AFTER `args`', $spy->queries[0]);
  }

  // R5 for v9 (every added column nullable or defaulted) is checked on the
  // migrated MySQL schema itself: tests/Integration/V8/SchemaV8MigrationTest
  // test_every_column_v9_adds_to_a_0_6_table_is_nullable_or_defaulted.

  /** A wpdb that answers the information_schema probe and records every query. */
  private function spyWpdb(bool $column_exists): \wpdb {
    $spy = new class extends \wpdb {
      public array $queries = [];
      public int $exists = 0;
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return $this->exists;
      }
      public function query(string $query) {
        $this->queries[] = $query;
        return true;
      }
    };
    $spy->exists = $column_exists ? 1 : 0;
    $GLOBALS['wpdb'] = $spy;
    return $spy;
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
