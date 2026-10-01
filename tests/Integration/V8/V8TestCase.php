<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;

use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\install_tables;

/**
 * Base for the schema v8 integration tests (real WordPress, MySQL 8, the
 * real Action Scheduler).
 *
 * Unlike IntegrationTestCase there is NO outer transaction: the v8 adapters
 * open their own (claim, dead-letter, intents), and a raw START TRANSACTION
 * by the test would be committed implicitly by the first of them. Instead
 * every test runs on the consumer prefix `ddd8it`, whose tables, options and
 * Action Scheduler actions are dropped before and after each test.
 */
abstract class V8TestCase extends TestCase {

  protected const PREFIX = 'ddd8it';

  protected DDDConfig $config;

  protected \wpdb $wpdb;

  protected function setUp(): void {
    parent::setUp();
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->config = new DDDConfig(static::PREFIX, 'TangibleDDD\\Tests\\Integration\\V8\\Fakes', '8.0-test');
    WpdbTransactionDepth::reset_for_tests();
    HostDefaults::reset_for_tests();
    HostDefaultsWiring::register();
    ConsumerRegistry::add($this->config, static fn () => null, 'v8 tests', 'TangibleDDD\\Tests\\Integration\\V8\\Fakes');
    $this->wipe();
  }

  protected function tearDown(): void {
    $this->wipe();
    WpdbTransactionDepth::reset_for_tests();
    HostDefaults::reset_for_tests();
    HostDefaultsWiring::register();
    parent::tearDown();
  }

  /** A fresh v8 install, as an activation or the first admin_init would leave it. */
  protected function installV8(): void {
    if (!function_exists('dbDelta')) {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    install_tables($this->config);
    update_option(ddd_schema_version_key($this->config), 8, false);
  }

  /** A fresh install at the current schema (v9 since wave 5: the parked-fact column). */
  protected function installCurrent(): void {
    if (!function_exists('dbDelta')) {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    install_tables($this->config);
    update_option(ddd_schema_version_key($this->config), \TangibleDDD\WordPress\DDD_SCHEMA_VERSION, false);
  }

  protected function table(string $name): string {
    return $this->config->table($name);
  }

  /** @return list<array<string, mixed>> */
  protected function rows(string $sql): array {
    $rows = $this->wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  protected function columnExists(string $table, string $column): bool {
    return (int) $this->wpdb->get_var($this->wpdb->prepare(
      'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
      $table,
      $column
    )) === 1;
  }

  protected function tableExists(string $table): bool {
    return (string) $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
  }

  /** @return list<object> pending Action Scheduler actions on $hook */
  protected function pendingActions(string $hook): array {
    $ids = as_get_scheduled_actions(['hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1], 'ids');
    return array_map(static fn ($id) => (object) [
      'id' => (int) $id,
      'args' => \ActionScheduler::store()->fetch_action((string) $id)->get_args(),
      'due' => \ActionScheduler::store()->fetch_action((string) $id)->get_schedule()->get_date()?->getTimestamp(),
    ], array_values((array) $ids));
  }

  private function wipe(): void {
    global $wp_filter;
    foreach (array_keys((array) $wp_filter) as $hook) {
      if (str_starts_with((string) $hook, static::PREFIX . '_')) {
        remove_all_actions((string) $hook);
      }
    }

    $like = $this->wpdb->esc_like($this->wpdb->prefix . static::PREFIX . '_') . '%';
    foreach ((array) $this->wpdb->get_col($this->wpdb->prepare(
      'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
      $like
    )) as $table) {
      $this->wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $this->wpdb->query($this->wpdb->prepare(
      "DELETE FROM `{$this->wpdb->options}` WHERE option_name LIKE %s",
      $this->wpdb->esc_like(static::PREFIX . '_') . '%'
    ));
    wp_cache_flush();

    $actions = $this->wpdb->prefix . 'actionscheduler_actions';
    if ($this->tableExists($actions)) {
      $this->wpdb->query($this->wpdb->prepare(
        "DELETE FROM `{$actions}` WHERE hook LIKE %s",
        $this->wpdb->esc_like(static::PREFIX . '_') . '%'
      ));
    }
  }
}
