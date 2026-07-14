<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\IDDDConfig;

use function TangibleDDD\WordPress\outbox_enabled;
use function TangibleDDD\WordPress\processes_enabled;

if (!function_exists('TangibleDDD\\WordPress\\processes_enabled')) {
  require_once __DIR__ . '/../../../ddd-wordpress/hooks.php';
  require_once __DIR__ . '/../../../ddd-wordpress/tables.php';
  require_once __DIR__ . '/../../../ddd-wordpress/migrations.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerHandle.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerRegistry.php';
}

/**
 * The WP test harness wraps CREATE TABLE as CREATE TEMPORARY TABLE, and
 * temporary tables are invisible to SHOW TABLES — a probe built on it reads
 * every consumer integration-test environment as "feature off" (process
 * discovery silently skipped, outbox lane closed). The probe must detect
 * any table a query can reach, so: SELECT 1 ... LIMIT 1 under
 * suppress_errors, existence = query didn't error.
 */
class TableProbeTest extends TestCase {

  /** wpdb whose tables exist but are invisible to SHOW TABLES (temp tables). */
  private function temp_table_wpdb(): \wpdb {
    return new class extends \wpdb {
      public array $queries_seen = [];
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return null; // SHOW TABLES: temp tables never listed
      }
      public function query(string $query) {
        $this->queries_seen[] = $query;
        return 0; // SELECT reaches the (empty) temp table: 0 rows, NOT false
      }
    };
  }

  /** wpdb with no tables at all: every query errors. */
  private function missing_table_wpdb(): \wpdb {
    return new class extends \wpdb {
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return null;
      }
      public function query(string $query) {
        $this->last_error = "Table doesn't exist";
        return false;
      }
    };
  }

  private function config(string $prefix): IDDDConfig {
    return new class($prefix) implements IDDDConfig {
      public function __construct(private readonly string $p) {}
      public function prefix(): string { return $this->p; }
      public function table(string $name): string { return 'wp_' . $this->p . '_' . $name; }
      public function hook(string $name): string { return $this->p . '_' . $name; }
      public function as_group(string $name): string { return $this->p . '-' . $name; }
      public function option(string $name): string { return $this->p . '_' . $name; }
      public function domain_action(string $e): string { return $this->p . '_domain_' . $e; }
      public function integration_action(string $e): string { return $this->p . '_integration_' . $e; }
      public function version(): string { return 'test'; }
    };
  }

  public function test_processes_enabled_sees_temp_tables(): void {
    $GLOBALS['wpdb'] = $this->temp_table_wpdb();
    $this->assertTrue(processes_enabled($this->config('probe_a')));
  }

  public function test_outbox_enabled_sees_temp_tables(): void {
    $GLOBALS['wpdb'] = $this->temp_table_wpdb();
    $this->assertTrue(outbox_enabled($this->config('probe_b')));
  }

  public function test_probes_stay_false_when_table_is_missing(): void {
    $GLOBALS['wpdb'] = $this->missing_table_wpdb();
    $this->assertFalse(processes_enabled($this->config('probe_c')));
    $this->assertFalse(outbox_enabled($this->config('probe_d')));
  }
}
