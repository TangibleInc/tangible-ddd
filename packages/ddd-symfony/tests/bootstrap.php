<?php
/**
 * ddd-symfony test bootstrap: this package's Composer autoload (tangible/ddd-core
 * through the ../ddd-core path repository) and nothing from WordPress.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-symfony bootstrap: a WordPress symbol is defined; the Symfony host must run without WordPress.\n");
  exit(1);
}

\TangibleDDD\Symfony\Tests\Support\PostgresDatabase::ensureDatabaseExists();
