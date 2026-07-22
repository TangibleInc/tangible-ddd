<?php
/**
 * Integration-test bootstrap for tangible-ddd.
 *
 * tangible-ddd is a library, not a WP plugin, so we boot WP directly via
 * wp-load.php and then initialize the tangible-datastream DI container from
 * .reference/tangible-datastream/ as the "host" that wires all the domain
 * infrastructure (command bus, repositories, outbox, etc.).
 *
 * DB isolation strategy (mirrors tangible-datastream's harness):
 *   - DB constants overridden BEFORE wp-load, pointing at db_test.
 *   - Per-test DML isolation via IntegrationTestCase (SET autocommit=0 +
 *     START TRANSACTION / ROLLBACK) — see that class for DDL caveats.
 *   - Commands that use ITransactionalCommand extend CommandIntegrationTestCase
 *     instead (no outer transaction; explicit DELETE in tearDown).
 *
 * Run inside ddev:
 *   ddev exec "cd wp-content/plugins/tangible-ddd && ./vendor/bin/phpunit -c phpunit.integration.xml"
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// ── Point WP at the isolated test DB ────────────────────────────────────────

define('DB_NAME',     getenv('WP_TESTS_DB_NAME')     ?: 'db_test');
define('DB_USER',     getenv('WP_TESTS_DB_USER')     ?: 'db');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'db');
define('DB_HOST',     getenv('WP_TESTS_DB_HOST')     ?: 'ddev-anything-db');

global $table_prefix;
$table_prefix = 'wptests_';

define('ABSPATH', '/var/www/html/');

ob_start();
require_once ABSPATH . 'wp-load.php';
ob_end_clean();

// dbDelta() lives in wp-admin/includes/upgrade.php, which wp-load.php does not
// pull in for non-admin contexts. The framework table installers (tables.php)
// call dbDelta(), so load it explicitly or table creation fatals with
// "Call to undefined function TangibleDDD\WordPress\dbDelta()".
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// ── Boot the datastream DI container (from .reference) ──────────────────────
// This wires the command bus, middlewares, repositories, and all domain
// infrastructure that the integration tests drive.

$_datastream_ref = dirname(__DIR__, 2) . '/.reference/tangible-datastream';

if (!is_dir($_datastream_ref)) {
    throw new RuntimeException(
        "tangible-ddd: .reference/tangible-datastream not found.\n" .
        "Run: ddev exec \"cp -r /var/www/html/plugins/tangible-datastream " .
        "/var/www/html/wp-content/plugins/tangible-ddd/.reference/tangible-datastream\"\n"
    );
}

// Register datastream rule node types before the DI container compiles.
// (datastream classes aren't autoloaded until di/index.php below, so require the
// two files explicitly; RuleNodeRegistrar reads the node map from RuleRegistration.)
require_once $_datastream_ref . '/src/Domain/Rules/RuleRegistration.php';
require_once $_datastream_ref . '/src/Infra/Rules/RuleNodeRegistrar.php';
\Tangible\Datastream\Infra\Rules\RuleNodeRegistrar::register();

// Boot the DI container (defines \Tangible\Datastream\WordPress\DI\di()).
require_once $_datastream_ref . '/includes/di/index.php';

// Announce datastream to the ConsumerRegistry, exactly as the plugin's real
// boot() does first thing. Since the self-handling conversion, consumer
// commands route via ConsumerRegistry::owner_of() — without this, any
// dispatch throws NoConsumerOwnsClass ("Known namespace roots: (none)").
\TangibleDDD\Infra\Consumers\ConsumerRegistry::add(
    \Tangible\Datastream\WordPress\DI\di()->get(\Tangible\Datastream\Infra\DatastreamConfig::class),
    static fn() => \Tangible\Datastream\WordPress\DI\di()
);

unset($_datastream_ref);

// ── Autoload integration test base classes ───────────────────────────────────
// The vendor autoload maps TangibleDDD\Tests\ → the parent plugin's tests/ dir.
// Integration base classes live in this worktree's tests/Integration/, so we
// require them explicitly here so PHPUnit can locate them.
require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/CommandIntegrationTestCase.php';
