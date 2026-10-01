<?php
/**
 * The WordPress stubs a consumer's test bootstrap defines (tangible-lms
 * tests/Integration/bootstrap.php): get_option backed by an array, and,
 * unless $mode is `options-only`, add_action, add_filter, apply_filters,
 * update_option, is_multisite, get_locale and a minimal wpdb. No
 * plugins_loaded ever fires. Required by late-wordpress-boot.php after or
 * before vendor/autoload.php, depending on its mode.
 *
 * Declared conditionally, as LMS does, so PHP does not hoist them to compile
 * time (an unconditional top-level function would exist before the include).
 *
 * @var string $mode
 */

declare(strict_types=1);

$GLOBALS['__test_wp_options'] = [];
if (!function_exists('get_option')) {
  function get_option(string $option, mixed $default = false): mixed {
    return array_key_exists($option, $GLOBALS['__test_wp_options']) ? $GLOBALS['__test_wp_options'][$option] : $default;
  }
}
if ($mode !== 'options-only' && !function_exists('add_action')) {
  function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    foreach ($GLOBALS['__test_wp_filters'][$hook] ?? [] as $callback) {
      $value = $callback($value, ...$args);
    }
    return $value;
  }
  function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['__test_wp_filters'][$hook][] = $callback;
    return true;
  }
  function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    return add_filter($hook, $callback, $priority, $accepted_args);
  }
  function update_option(string $option, mixed $value, mixed $autoload = null): bool {
    $GLOBALS['__test_wp_options'][$option] = $value;
    return true;
  }
  function is_multisite(): bool {
    return false;
  }
  function get_locale(): string {
    return 'en_US';
  }
  class wpdb {
    public string $prefix = 'wptests_';
    /** @var list<string> */
    public array $queries = [];
    public function query(string $query) { $this->queries[] = $query; return true; }
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_var(?string $query = null, int $x = 0, int $y = 0) { return null; }
  }
  $GLOBALS['wpdb'] = new wpdb();
}
