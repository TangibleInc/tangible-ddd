<?php
/**
 * ddd-core unit bootstrap: Composer autoload only. No wp-stubs.php, no
 * WordPress function is defined here, so core code that reaches for one fails
 * the suite instead of passing against a stub.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';

// The core package's own test namespace (fixtures live next to the tests).
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Unit\\', __DIR__ . '/');

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-core unit bootstrap: a WordPress symbol is defined; this suite must run without WordPress.\n");
  exit(1);
}
