<?php
/**
 * Install the integration host's tables into the fresh harness database, the
 * way activating the host plugin would (datastream's tangible_datastream_activate():
 * framework tables via install_tables(), then its four domain tables).
 *
 * Run inside the runner container with cwd = the exported tangible-ddd root.
 * Boots exactly as tests/Integration/bootstrap.php does (Composer autoload
 * first, then wp-load, then the datastream container), so the suite and the
 * installer see the same wiring. Without this step a fresh database lacks
 * behaviour_workflows_meta when BehaviourWorkflowForkTest runs and the test
 * passes on swallowed wpdb errors (report F-11).
 */

declare(strict_types=1);

$root = getcwd();
require_once $root . '/vendor/autoload.php';

global $table_prefix;
$table_prefix = 'wptests_';

define('ABSPATH', rtrim((string) (getenv('WP_TESTS_ABSPATH') ?: '/var/www/html/'), '/') . '/');
define('DOING_TANGIBLE_TESTS', 1);

ob_start();
require_once ABSPATH . 'wp-load.php';
ob_end_clean();
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$ref = $root . '/.reference/tangible-datastream';
if (!is_dir($ref)) {
    fwrite(STDERR, "install-tables.php: {$ref} missing\n");
    exit(1);
}

require_once $ref . '/src/Domain/Rules/RuleRegistration.php';
require_once $ref . '/src/Infra/Rules/RuleNodeRegistrar.php';
\Tangible\Datastream\Infra\Rules\RuleNodeRegistrar::register();
require_once $ref . '/includes/di/index.php';

$config = \Tangible\Datastream\WordPress\DI\di()->get(\Tangible\Datastream\Infra\DatastreamConfig::class);

global $wpdb;
$wpdb->suppress_errors(false);
$wpdb->show_errors(false);

\TangibleDDD\WordPress\install_tables($config);

foreach (['subscriptions', 'captured-events', 'destinations', 'delivery-log'] as $file) {
    require_once $ref . '/includes/database/' . $file . '.php';
}
tangible_datastream_install_subscriptions_table($config);
tangible_datastream_install_captured_events_table($config);
tangible_datastream_install_destinations_table($config);
tangible_datastream_install_delivery_log_table($config);

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "install-tables.php: wpdb error: {$wpdb->last_error}\n");
    exit(1);
}

echo "install: framework and host tables installed for prefix {$config->prefix()}\n";
