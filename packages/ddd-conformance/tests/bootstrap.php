<?php
/**
 * ddd-conformance bootstrap: this package's Composer autoload (which pulls
 * tangible/ddd-core through the ../ddd-core path repository) and nothing from
 * WordPress.
 *
 * WAVE-1 BRIDGE (remove at the wave-2 move): the 0.6 classes the core ports
 * build on (ITransactionalCommand, Correlation, EventsUnitOfWork,
 * IntegrationEnvelope, IntegrationEvent, ...) still live in the monorepo's
 * ddd-src/ and only move into packages/ddd-core/src in wave 2. Until then we
 * map TangibleDDD\ to ddd-src/ as a FALLBACK after ddd-core's own map, so a
 * class that already lives in ddd-core always wins. Only class files are
 * reachable this way; no ddd-wordpress/*.php function file is loaded, so any
 * scenario path that reaches a WordPress function fails loudly.
 */

declare(strict_types=1);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// Probe by file, not interface_exists(): a failed autoload is remembered by
// Composer's ClassLoader as a missing class and would stay missing.
if (!is_file(dirname(__DIR__) . '/vendor/tangible/ddd-core/src/Application/Commands/ITransactionalCommand.php')) {
  $legacy = dirname(__DIR__, 3) . '/ddd-src/';
  if (!is_dir($legacy)) {
    fwrite(STDERR, "ddd-conformance bootstrap: ddd-core lacks the 0.6 classes and no monorepo ddd-src/ was found at $legacy.\n");
    exit(1);
  }
  $loader->addPsr4('TangibleDDD\\', $legacy); // appended: ddd-core's src/ is consulted first
}

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-conformance bootstrap: a WordPress symbol is defined; the mem host must run without WordPress.\n");
  exit(1);
}
