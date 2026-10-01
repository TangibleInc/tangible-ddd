<?php
/**
 * ddd-core Defaults/Pdo suite bootstrap: Composer autoload only (no
 * WordPress), then a fresh database of this suite's own, with the schema
 * applied the way a host would apply it (the library never does).
 */

declare(strict_types=1);

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;

$root = dirname(__DIR__, 4);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Pdo\\', __DIR__ . '/');
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Unit\\', dirname(__DIR__) . '/Unit/');

if (function_exists('add_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-core pdo bootstrap: a WordPress symbol is defined; this suite must run without WordPress.\n");
  exit(1);
}

PdoTestCase::prepareDatabase();
