<?php
/**
 * ddd-conformance bootstrap: this package's Composer autoload (which pulls
 * tangible/ddd-core through the ../ddd-core path repository) and nothing
 * else. No monorepo source directory is mapped and no WordPress file is
 * loaded, so a scenario path that reaches a class outside ddd-core, or a
 * WordPress function, fails loudly.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (function_exists('add_action') || function_exists('do_action') || class_exists('wpdb', false)) {
  fwrite(STDERR, "ddd-conformance bootstrap: a WordPress symbol is defined; the mem host must run without WordPress.\n");
  exit(1);
}
