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

// WAVE-2 ROUND-1 BRIDGE (replaces the wave-1 ddd-src/ bridge): the 0.6 classes
// now live in ddd-core, except the split classes whose core half moves onto
// the ports in round 2 and which sit in packages/ddd-wp/src/ until then
// (OutboxConfig is the one the mem host constructs). Appended after
// ddd-core's own map, so a class in ddd-core always wins; no WordPress
// function file is loaded, so a path that reaches WordPress still fails
// loudly. Remove once OutboxConfig's core form is in ddd-core.
$wpSrc = dirname(__DIR__, 2) . '/ddd-wp/src/';
if (is_dir($wpSrc)) {
  $loader->addPsr4('TangibleDDD\\', $wpSrc);
}

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-conformance bootstrap: a WordPress symbol is defined; the mem host must run without WordPress.\n");
  exit(1);
}
