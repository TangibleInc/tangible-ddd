<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tangible\Datastream\Infra\Rules\RuleNodeRegistrar;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;

/**
 * Base for command-bus integration tests.
 *
 * Why NOT IntegrationTestCase (which wraps each test in a transaction):
 * ITransactionalCommand causes TransactionMiddleware to issue START TRANSACTION
 * / COMMIT. MySQL/MariaDB has no true nested transactions — an inner START
 * TRANSACTION implicitly commits the outer one. If we extended IntegrationTestCase
 * the outer transaction's ROLLBACK in tearDown() would be a no-op and rows would
 * leak into db_test.
 *
 * Strategy: extend plain TestCase, skip the outer-transaction wrap entirely, and
 * DELETE rows created by each test in tearDown() using a known discriminator column
 * value (via the protected helper cleanup_rows()). This keeps db_test clean without
 * requiring a nested-transaction-aware MySQL driver.
 *
 * Shared framework singletons reset on setUp/tearDown:
 *   - EventsUnitOfWork: the command bus pipeline seals the UoW after drain; without
 *     a reset, subsequent tests find a sealed UoW and throw on save().
 *   - CorrelationContext: static state; a leftover correlation_id from a previous
 *     command would pollute the audit trail of the next.
 */
abstract class CommandIntegrationTestCase extends TestCase
{
    // ── Per-test setup ──────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Register rule node types (required for SubscriptionRepository reconstitution).
        RuleNodeRegistrar::register();

        // Boot (or reuse) the compiled DI container.
        // bootstrap.php already loaded it once; subsequent calls reuse the singleton.
        require_once dirname(__DIR__, 2) . '/.reference/tangible-datastream/includes/di/index.php';

        // Reset shared framework singletons so prior test runs don't leak state.
        $this->reset_framework_singletons();
    }

    // ── Per-test teardown ───────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        // Reset again after the test so the next test starts clean.
        $this->reset_framework_singletons();

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Reset both shared framework singletons (EventsUnitOfWork + CorrelationContext).
     *
     * Called in both setUp and tearDown — before and after — so a test that throws
     * mid-command still leaves things clean for the next test.
     */
    private function reset_framework_singletons(): void
    {
        \Tangible\Datastream\WordPress\DI\di()
            ->get(EventsUnitOfWork::class)
            ->reset();

        CorrelationContext::reset();
    }

    /**
     * Scoped DELETE helper for subclass tearDown methods.
     *
     * Deletes rows from $table WHERE $column = $value. Use a test-run discriminator
     * (e.g. a unique source name) so only rows this test class created are removed.
     *
     * @param string $table  Fully-qualified table name (e.g. from DatastreamConfig::table()).
     * @param string $column Column used to scope the delete.
     * @param string $value  Discriminator value (cast to string for %s placeholder).
     */
    protected function cleanup_rows(string $table, string $column, string $value): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `{$column}` = %s",
                $value,
            )
        );
    }
}
