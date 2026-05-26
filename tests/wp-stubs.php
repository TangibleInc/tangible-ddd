<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only defines functions actually called by framework code under test.
 * These are intentionally simple — they make the code runnable, not WordPress-compatible.
 */

if (!class_exists('wpdb')) {
  /**
   * Minimal wpdb stub for unit tests.
   * Methods are defined so they can be mocked by PHPUnit.
   */
  class wpdb {
    public string $prefix = 'wp_';
    public ?string $last_error = '';
    public ?int $insert_id = 0;

    public function query(string $query) { return true; }
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_var(?string $query = null, int $x = 0, int $y = 0) { return null; }
    public function get_row(?string $query = null, string $output = 'OBJECT', int $y = 0) { return null; }
    public function get_results(?string $query = null, string $output = 'OBJECT'): array { return []; }
    public function insert(string $table, array $data, $format = null): bool { return true; }
    public function update(string $table, array $data, array $where, $format = null, $where_format = null): bool { return true; }
    public function delete(string $table, array $where, $where_format = null): bool { return true; }
  }
}

if (!function_exists('wp_json_encode')) {
  function wp_json_encode($data, $options = 0, $depth = 512) {
    return json_encode($data, $options, $depth);
  }
}

if (!function_exists('is_multisite')) {
  function is_multisite(): bool {
    return false;
  }
}

if (!function_exists('get_current_blog_id')) {
  function get_current_blog_id(): int {
    return 1;
  }
}

if (!function_exists('get_current_user_id')) {
  function get_current_user_id(): int {
    return 0;
  }
}

if (!function_exists('get_bloginfo')) {
  function get_bloginfo(string $show = ''): string {
    return 'test';
  }
}

if (!function_exists('add_action')) {
  /** @var array<string, array<callable>> */
  global $_test_actions;
  $_test_actions = [];

  function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    global $_test_actions;
    $_test_actions[$hook][] = $callback;
  }
}

if (!function_exists('do_action')) {
  function do_action(string $hook, ...$args): void {
    global $_test_actions;
    foreach ($_test_actions[$hook] ?? [] as $callback) {
      $callback(...$args);
    }
  }
}

if (!function_exists('get_option')) {
  function get_option(string $option, $default = false) {
    return $default;
  }
}

if (!function_exists('wp_salt')) {
  function wp_salt(string $scheme = 'auth'): string {
    return 'test_salt_' . $scheme;
  }
}

if (!function_exists('as_enqueue_async_action')) {
  /** @var array<array{hook: string, args: array, group: string}> */
  global $_test_scheduled_actions;
  $_test_scheduled_actions = [];

  function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int {
    global $_test_scheduled_actions;
    $_test_scheduled_actions[] = ['hook' => $hook, 'args' => $args, 'group' => $group];
    return count($_test_scheduled_actions);
  }
}
