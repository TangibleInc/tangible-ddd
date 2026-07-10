<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Persistence;

use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * Proves a WorkItem with WorkItemStatus::cancelled round-trips through the
 * WPDB item repository. Guards the DDL ENUM against silently dropping 'cancelled'.
 *
 * NOTE: This test requires the DDEV integration harness (ddev-anything-db + wp-load.php).
 * It cannot run in the local unit environment. Run via:
 *   ddev exec "cd wp-content/plugins/tangible-ddd && ./vendor/bin/phpunit -c phpunit.integration.xml --filter test_cancelled_status_round_trips_through_wpdb"
 *
 * Why it exists: WorkItemStatus::cancelled is written by WorkItemRepository::save() when a
 * Stop behaviour executes. The DDL previously omitted 'cancelled' from the ENUM, causing
 * MySQL to silently truncate it to '' (or error under STRICT_ALL_TABLES), corrupting ledger
 * rows for every Stop workflow. This test is the regression guard for that DDL fix.
 */
final class WorkItemRepositoryCancelledTest extends IntegrationTestCase
{
    /** Unique workflow_id discriminator — arbitrary, unlikely to collide with real data. */
    private const WORKFLOW_ID = 99991;

    private IDDDConfig $config;
    private IWorkItemRepository $item_repo;

    // ── Schema setup (DDL is auto-committed by MySQL; run once per suite) ─────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $di = \Tangible\Datastream\WordPress\DI\di();

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_behaviour_workflow_item_tables($di->get(IDDDConfig::class));
    }

    // ── Per-test setup ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $di              = \Tangible\Datastream\WordPress\DI\di();
        $this->config    = $di->get(IDDDConfig::class);
        $this->item_repo = $di->get(IWorkItemRepository::class);
    }

    // ── Per-test teardown ─────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        // ROLLBACK from parent (may be a no-op if inner writes auto-committed).
        parent::tearDown();

        // Explicit DELETE scoped by our fixed workflow_id discriminator.
        $item_table = $this->config->table('behaviour_workflow_items');
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$item_table}` WHERE workflow_id = %d",
            self::WORKFLOW_ID
        ));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * A WorkItem saved with WorkItemStatus::cancelled must be retrievable with the
     * same status — i.e. 'cancelled' must survive the WPDB ENUM round-trip.
     *
     * Before the DDL fix this would fail with a ValueError thrown from
     * WorkItemStatus::from('') when MySQL silently truncated 'cancelled' to ''.
     */
    public function test_cancelled_status_round_trips_through_wpdb(): void
    {
        $item = new WorkItem(
            id:            null,
            workflow_id:   self::WORKFLOW_ID,
            behaviour_idx: 0,
            phase:         1,
            item_key:      'req:' . self::WORKFLOW_ID,
            status:        WorkItemStatus::cancelled,
        );

        $this->item_repo->save($item);

        $saved_id = $item->get_id();
        $this->assertNotNull($saved_id, 'WorkItem must receive a DB id after save()');

        $reloaded = $this->item_repo->get_by_id($saved_id);

        $this->assertSame(
            WorkItemStatus::cancelled,
            $reloaded->status,
            "'cancelled' must survive the WPDB ENUM round-trip — DDL must include it"
        );
    }
}
