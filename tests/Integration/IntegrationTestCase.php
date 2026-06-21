<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration;

use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * Base for tangible-ddd WP integration tests.
 *
 * WordPress is already booted by tests/Integration/bootstrap.php (real $wpdb,
 * isolated db_test). Each test runs inside a DB transaction that ROLLS BACK in
 * tearDown, so row-level mutations never leak between tests.
 *
 * Caveats:
 *  - DDL (CREATE/ALTER/DROP TABLE) is auto-committed by MySQL/MariaDB and is NOT
 *    rolled back. Create schema ONCE (idempotent, e.g. setUpBeforeClass / dbDelta)
 *    and keep per-test work to DML.
 *  - Commands implementing ITransactionalCommand cause TransactionMiddleware to
 *    issue START TRANSACTION / COMMIT, which auto-commits any outer transaction.
 *    Those tests must NOT extend this base; extend CommandIntegrationTestCase instead.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        // Begin an isolated transaction for this test.
        $this->wpdb->query('SET autocommit = 0');
        $this->wpdb->query('START TRANSACTION');
    }

    protected function tearDown(): void
    {
        // Undo every DML mutation this test made.
        $this->wpdb->query('ROLLBACK');
        $this->wpdb->query('SET autocommit = 1');

        parent::tearDown();
    }
}
