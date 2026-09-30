<?php
/**
 * ddd-symfony test bootstrap: this package's Composer autoload (tangible/ddd-core
 * through the ../ddd-core path repository) and nothing from WordPress.
 *
 * WAVE-2 BRIDGE (remove once the core/wp move lands in packages/ddd-core/src):
 * the 0.6 classes the adapters build on (ITransactionalCommand, Correlation,
 * EventsUnitOfWork, IntegrationEnvelope, DomainEvent, ConsumerRegistry, ...)
 * still live in the monorepo's ddd-src/. Until they move, TangibleDDD\ is
 * mapped to ddd-src/ as a FALLBACK after ddd-core's own map, so a class that
 * already lives in ddd-core always wins. Only class files are reachable this
 * way; no ddd-wordpress/*.php function file is loaded, so any path that
 * reaches a WordPress function fails loudly. The same bridge is what
 * examples/symfony/README.md documents for an app during the transition.
 */

declare(strict_types=1);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// Probe by file, not interface_exists(): a failed autoload is remembered.
if (!is_file(dirname(__DIR__) . '/vendor/tangible/ddd-core/src/Application/Commands/ITransactionalCommand.php')) {
  $legacy = dirname(__DIR__, 3) . '/ddd-src/';
  if (!is_dir($legacy)) {
    fwrite(STDERR, "ddd-symfony bootstrap: ddd-core lacks the 0.6 classes and no monorepo ddd-src/ was found at $legacy.\n");
    exit(1);
  }
  $loader->addPsr4('TangibleDDD\\', $legacy); // appended: ddd-core's src/ is consulted first
}

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-symfony bootstrap: a WordPress symbol is defined; the Symfony host must run without WordPress.\n");
  exit(1);
}

\TangibleDDD\Symfony\Tests\Support\PostgresDatabase::ensureDatabaseExists();
