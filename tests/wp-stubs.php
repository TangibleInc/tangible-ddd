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
    public function suppress_errors(bool $suppress = true): bool { return false; }
    public function prepare(string $query, ...$args): string { return $query; }
    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function get_var(?string $query = null, int $x = 0, int $y = 0) { return null; }
    public function get_row(?string $query = null, string $output = 'OBJECT', int $y = 0) { return null; }
    public function get_results(?string $query = null, string $output = 'OBJECT'): array { return []; }
    public function get_col(?string $query = null, int $x = 0): array { return []; }
    public function insert(string $table, array $data, $format = null): bool { return true; }
    public function update(string $table, array $data, array $where, $format = null, $where_format = null): bool { return true; }
    public function delete(string $table, array $where, $where_format = null): bool { return true; }
  }
}

if (!class_exists('WP_REST_Request')) {
  class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params = []) {}
    public function get_param(string $key) { return $this->params[$key] ?? null; }
    public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
  }
}

if (!class_exists('WP_Error')) {
  class WP_Error {
    public function __construct(
      public string $code = '',
      public string $message = '',
      public mixed $data = null,
    ) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
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

if (!function_exists('remove_action')) {
  function remove_action(string $hook, callable|string|array $callback, int $priority = 10): bool {
    global $_test_actions;
    $before = count($_test_actions[$hook] ?? []);
    $_test_actions[$hook] = array_values(array_filter(
      $_test_actions[$hook] ?? [],
      static fn ($registered) => $registered !== $callback
    ));
    return count($_test_actions[$hook]) !== $before;
  }
}

if (!function_exists('do_action_ref_array')) {
  /** Same named-arg dispatch as real WP: string keys become named parameters. */
  function do_action_ref_array(string $hook, array $args): void {
    do_action($hook, ...$args);
  }
}

if (!function_exists('add_filter')) {
  /** @var array<string, array<callable>> */
  global $_test_filters;
  $_test_filters = [];

  function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    global $_test_filters;
    $_test_filters[$hook][] = $callback;
  }
}

if (!function_exists('apply_filters')) {
  function apply_filters(string $hook, $value, ...$args) {
    global $_test_filters;
    foreach ($_test_filters[$hook] ?? [] as $callback) {
      $value = $callback($value, ...$args);
    }
    return $value;
  }
}

if (!function_exists('current_user_can')) {
  global $_test_current_user_can;
  $_test_current_user_can = true;
  function current_user_can(string $capability): bool {
    global $_test_current_user_can;
    return (bool) $_test_current_user_can;
  }
}

if (!function_exists('register_rest_route')) {
  global $_test_rest_routes;
  $_test_rest_routes = [];
  function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool {
    global $_test_rest_routes;
    $_test_rest_routes[$namespace . $route] = compact('namespace', 'route', 'args', 'override');
    return true;
  }
}

if (!function_exists('rest_ensure_response')) {
  function rest_ensure_response(mixed $response): mixed { return $response; }
}

if (!function_exists('rest_url')) {
  function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}

if (!function_exists('esc_url_raw')) {
  function esc_url_raw(string $url): string { return $url; }
}

if (!function_exists('wp_create_nonce')) {
  function wp_create_nonce(string|int $action = -1): string { return 'test-nonce'; }
}

if (!function_exists('plugins_url')) {
  function plugins_url(string $path = '', string $plugin = ''): string {
    return 'https://example.test/wp-content/plugins/tangible-ddd/' . ltrim($path, '/');
  }
}

if (!function_exists('add_management_page')) {
  global $_test_management_pages;
  $_test_management_pages = [];
  function add_management_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback): string {
    global $_test_management_pages, $_test_actions;
    $hook = 'tools_page_' . $menu_slug;
    $_test_management_pages[$menu_slug] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'hook');
    $_test_actions[$hook][] = $callback;
    return $hook;
  }
}

if (!function_exists('remove_submenu_page')) {
  global $_test_removed_submenus;
  $_test_removed_submenus = [];
  function remove_submenu_page(string $menu_slug, string $submenu_slug): array|false {
    global $_test_removed_submenus;
    $_test_removed_submenus[] = [$menu_slug, $submenu_slug];
    return [];
  }
}

if (!function_exists('wp_enqueue_script')) {
  global $_test_enqueued_scripts;
  $_test_enqueued_scripts = [];
  function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $in_footer = false): void {
    global $_test_enqueued_scripts;
    $_test_enqueued_scripts[$handle] = compact('src', 'deps', 'ver', 'in_footer');
  }
}

if (!function_exists('wp_enqueue_style')) {
  global $_test_enqueued_styles;
  $_test_enqueued_styles = [];
  function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void {
    global $_test_enqueued_styles;
    $_test_enqueued_styles[$handle] = compact('src', 'deps', 'ver', 'media');
  }
}

if (!function_exists('wp_add_inline_script')) {
  global $_test_inline_scripts;
  $_test_inline_scripts = [];
  function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool {
    global $_test_inline_scripts;
    $_test_inline_scripts[$handle][] = compact('data', 'position');
    return true;
  }
}

if (!function_exists('get_current_screen')) {
  global $_test_current_screen;
  $_test_current_screen = null;
  function get_current_screen(): mixed {
    global $_test_current_screen;
    return $_test_current_screen;
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

if (!function_exists('as_schedule_single_action')) {
  /** @var array<array{timestamp: int, hook: string, args: array, group: string}> */
  global $_test_scheduled_actions;
  $_test_scheduled_actions ??= [];

  function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int {
    global $_test_scheduled_actions;
    $_test_scheduled_actions[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group];
    return count($_test_scheduled_actions);
  }
}
