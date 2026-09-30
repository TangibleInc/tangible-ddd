<?php
/**
 * wp-config.php written by tests/harness/run.sh into WP_TESTS_ABSPATH.
 *
 * Every constant is guarded by defined(): tests/Integration/bootstrap.php
 * defines the DB constants before wp-load.php, and an unguarded config would
 * redefine them (4 "Constant already defined" warnings, report F-12).
 * Everything comes from the environment the harness passes into the container.
 */

defined('DB_NAME')     || define('DB_NAME',     getenv('WP_TESTS_DB_NAME') ?: 'db_test');
defined('DB_USER')     || define('DB_USER',     getenv('WP_TESTS_DB_USER') ?: 'root');
defined('DB_PASSWORD') || define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
defined('DB_HOST')     || define('DB_HOST',     getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
defined('DB_CHARSET')  || define('DB_CHARSET',  'utf8mb4');
defined('DB_COLLATE')  || define('DB_COLLATE',  '');

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $k) {
    defined($k) || define($k, 'tangible-ddd-harness-' . $k);
}
unset($k);

$table_prefix = 'wptests_';

defined('WP_DEBUG')         || define('WP_DEBUG', (bool) getenv('DDD_WP_DEBUG'));
defined('WP_DEBUG_DISPLAY') || define('WP_DEBUG_DISPLAY', false);
defined('DISABLE_WP_CRON')  || define('DISABLE_WP_CRON', true);
defined('WP_HTTP_BLOCK_EXTERNAL') || define('WP_HTTP_BLOCK_EXTERNAL', true);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');

require_once ABSPATH . 'wp-settings.php';
