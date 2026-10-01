<?php
/**
 * Bootstrap of the WordPress conformance host (register section 4, host
 * column wp; `tests/harness/run.sh conformance-wp`).
 *
 * 1. The WP integration bootstrap unchanged: Composer autoload, the test
 *    database constants, wp-load.php, ddd-wp init (HostDefaultsWiring) and
 *    the datastream host container.
 * 2. Action Scheduler from the distribution's vendor/, initialized the way
 *    a plugin loaded after plugins_loaded gets it (its own "late" branch),
 *    so the wp transport is the real AS store on the WordPress connection.
 * 3. The ddd-conformance package classes (TangibleDDD\Conformance\), which
 *    the root manifest does not autoload.
 *
 * No clock shims: since schema v8 every wp adapter on the scenario paths
 * takes the host IClock (WPC-3). bin/fresh.php boots through this file too.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 3);

if (!function_exists('as_schedule_single_action')) {
  require_once $root . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}
if (!class_exists('ActionScheduler', false) || !ActionScheduler::is_initialized()) {
  fwrite(STDERR, "conformance-wp bootstrap: Action Scheduler did not initialize.\n");
  exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Conformance\\', $root . '/packages/ddd-conformance/src/');

define('TANGIBLE_DDD_WP_CONFORMANCE', true);

unset($root, $loader);
