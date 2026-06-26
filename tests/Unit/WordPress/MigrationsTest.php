<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;

use function TangibleDDD\WordPress\ddd_pending_migrations;

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
}
