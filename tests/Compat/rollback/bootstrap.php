<?php
/**
 * Bootstrap of the register 7.3 rollback fixtures (`tests/harness/run.sh
 * compat`, section 7.3): the WordPress conformance bootstrap (the WP
 * integration bootstrap, then Action Scheduler from the distribution's
 * vendor/), plus this directory's classes, whose directory name is not the
 * PSR-4 spelling of their namespace.
 *
 * The legacy winners (L-0.6.x) never load here: each runs in its own php
 * child (bin/legacy.php) against the same database. The harness passes the
 * installed copies as DDD_ROLLBACK_LEGACY="<version>=<dir> ...".
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Integration/Conformance/bootstrap.php';

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Tests\\Compat\\Rollback\\', __DIR__ . '/');
unset($loader);
