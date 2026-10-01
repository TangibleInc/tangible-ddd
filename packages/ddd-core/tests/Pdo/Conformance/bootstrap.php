<?php
/**
 * pdo conformance bootstrap: the root Composer autoload (ddd-core from
 * packages/ddd-core/src), the ddd-conformance scenarios from their package
 * source, and this directory. No WordPress symbol may be defined.
 *
 * Each test creates its own database (PdoHostFixture::setUp()), so nothing
 * is prepared here beyond a reachability check of the MySQL server.
 */

declare(strict_types=1);

use TangibleDDD\Core\Tests\Pdo\Conformance\Support\ConformanceDatabase;

$root = dirname(__DIR__, 5);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Conformance\\', $root . '/packages/ddd-conformance/src/');
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Pdo\\Conformance\\', __DIR__ . '/');

if (function_exists('add_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "pdo conformance bootstrap: a WordPress symbol is defined; this suite must run without WordPress.\n");
  exit(1);
}

ConformanceDatabase::assertReachable();
