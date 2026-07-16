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
  require_once __DIR__ . '/../../../ddd-wordpress/migrations.php';
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

  public function test_current_schema_version_is_5(): void {
    // Regression guard for the v3-fast-path bug: a consumer already recorded
    // as v3 must NOT be treated as up to date once await_mechanism ships.
    $this->assertSame(5, DDD_SCHEMA_VERSION);
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
