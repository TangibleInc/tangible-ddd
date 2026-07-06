<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * E2E-2 — Outbox publish → drain → DLQ status transitions (real DB).
 *
 * Exercises the full outbox lifecycle against a real MySQL db_test schema:
 *
 *   write()          → status='pending', attempts=0
 *   mark_completed() → status='completed'
 *   mark_failed()    → status='pending', attempts++, next_attempt_at set
 *   move_to_dlq()    → status='dlq', row in integration_dlq
 *   OutboxProcessor  → completed/failed/dlq counters correct
 *
 * No Action Scheduler is involved: the OutboxProcessor is injected with a
 * stub IOutboxPublisher so we can control success/failure per test.
 *
 * Extends IntegrationTestCase (outer transaction rollback) because nothing
 * here implements ITransactionalCommand — writes go through repository
 * methods directly.
 *
 * NOTE: OutboxRepository::fetch_pending() issues its own START TRANSACTION /
 * COMMIT internally (to lock rows atomically). This commits the outer
 * IntegrationTestCase transaction on MariaDB. We therefore tearDown by
 * deleting rows rather than relying on the outer rollback, using a known
 * correlation_id discriminator.
 */
final class OutboxPrimitiveTest extends IntegrationTestCase
{
    /** Discriminator — all rows this class creates share this correlation_id prefix. */
    private const CORRELATION_PREFIX = 'ddd_outbox_prim_test_';

    // $wpdb is inherited as protected from IntegrationTestCase (set in setUp)
    private IDDDConfig $config;
    private IOutboxRepository $outbox_repo;
    private string $outbox_table;
    private string $dlq_table;

    // ── Schema setup (DDL auto-commits; run once per suite) ──────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        global $wpdb;

        $config = \Tangible\Datastream\WordPress\DI\di()->get(IDDDConfig::class);

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_outbox_tables($config);
    }

    // ── Per-test setup ─────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $di = \Tangible\Datastream\WordPress\DI\di();
        $this->config = $di->get(IDDDConfig::class);
        $this->outbox_repo = $di->get(IOutboxRepository::class);

        $prefix          = $this->config->prefix();
        $this->outbox_table = $this->wpdb->prefix . $prefix . '_integration_outbox';
        $this->dlq_table    = $this->wpdb->prefix . $prefix . '_integration_dlq';
    }

    // ── Per-test teardown ──────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        // ROLLBACK from parent (may be no-op if fetch_pending already committed,
        // but harmless) — then DELETE test rows explicitly.
        parent::tearDown();

        // Discriminate by correlation_id prefix using LIKE.
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$this->outbox_table}` WHERE correlation_id LIKE %s",
            self::CORRELATION_PREFIX . '%'
        ));
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$this->dlq_table}` WHERE correlation_id LIKE %s",
            self::CORRELATION_PREFIX . '%'
        ));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Produce a unique correlation_id for a given test method.
     */
    private function correlation(string $suffix = ''): string
    {
        return self::CORRELATION_PREFIX . substr(md5($suffix ?: uniqid('', true)), 0, 8);
    }

    /**
     * Build a minimal IIntegrationEvent stub (a derived-only "twin" record).
     */
    private function make_event(array $payload = []): IIntegrationEvent
    {
        return new class($payload) extends IntegrationEvent {
            public function __construct(
                public readonly array $payload = []
            ) {}
            protected static function prefix(): string { return 'outbox'; }
            public static function name(): string { return 'outbox.test.event'; }
        };
    }

    /**
     * Build an OutboxProcessor with the given IOutboxPublisher stub.
     */
    private function make_processor(IOutboxPublisher $publisher): OutboxProcessor
    {
        $di     = \Tangible\Datastream\WordPress\DI\di();
        $config = $di->get(IDDDConfig::class);
        $repo   = $di->get(IOutboxRepository::class);
        $obconf = new OutboxConfig(batch_size: 10, max_attempts: 3);

        return new OutboxProcessor($config, $repo, $obconf, $publisher);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * write() inserts a pending row with the correct status and payload.
     */
    public function test_write_inserts_pending_row(): void
    {
        $correlation = $this->correlation('write');
        $event       = $this->make_event(payload: ['foo' => 'bar']);

        $event_id = $this->outbox_repo->write($event, $correlation);

        $this->assertNotEmpty($event_id, 'write() must return a non-empty event_id');

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertNotNull($row, 'A row must exist in outbox after write()');
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame($correlation, $row->correlation_id);

        $decoded = json_decode($row->payload, true);
        $this->assertSame(['payload' => ['foo' => 'bar']], $decoded);
    }

    /**
     * mark_completed() transitions status to 'completed' and records processed_at.
     */
    public function test_mark_completed_transitions_status(): void
    {
        $correlation = $this->correlation('completed');
        $event_id    = $this->outbox_repo->write($this->make_event(), $correlation);

        $this->outbox_repo->mark_completed($event_id);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->processed_at, 'processed_at must be set after mark_completed()');
    }

    /**
     * mark_failed() increments attempts, sets next_attempt_at, keeps status='pending'.
     */
    public function test_mark_failed_increments_attempts(): void
    {
        $correlation = $this->correlation('failed');
        $event_id    = $this->outbox_repo->write($this->make_event(), $correlation);

        $this->outbox_repo->mark_failed($event_id, 'simulated transport error');

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertSame('pending', $row->status, 'mark_failed() keeps status=pending for retry');
        $this->assertSame(1, (int) $row->attempts, 'attempts must be incremented to 1');
        $this->assertNotNull($row->next_attempt_at, 'next_attempt_at must be scheduled');
        $this->assertSame('simulated transport error', $row->last_error);
    }

    /**
     * move_to_dlq() sets status='dlq' and inserts a row in integration_dlq.
     */
    public function test_move_to_dlq_inserts_dlq_row(): void
    {
        $correlation = $this->correlation('dlq');
        $event_id    = $this->outbox_repo->write($this->make_event(), $correlation);

        $this->outbox_repo->move_to_dlq($event_id);

        $outbox_row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertSame('dlq', $outbox_row->status, 'Outbox row status must be dlq');

        $dlq_row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->dlq_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertNotNull($dlq_row, 'A row must be inserted into the DLQ table');
        $this->assertSame($event_id, $dlq_row->event_id);
        $this->assertSame($correlation, $dlq_row->correlation_id);
    }

    /**
     * OutboxProcessor: succeeding publisher → completed count = 1.
     */
    public function test_processor_marks_completed_on_success(): void
    {
        $correlation = $this->correlation('proc_ok');

        // Write and ensure it is immediately eligible (scheduled_at = now).
        $event_id = $this->outbox_repo->write($this->make_event(), $correlation);

        // Stub publisher: always succeeds (no-op).
        $publisher = new class implements IOutboxPublisher {
            public array $published = [];
            public function publish(OutboxEntry $entry, array $wrapped_payload): void {
                $this->published[] = $entry->event_id;
            }
        };

        $result = $this->make_processor($publisher)->process_batch();

        $this->assertContains($event_id, $publisher->published, 'Publisher must have been called for our event');

        // Row must now be completed.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertSame('completed', $row->status);
        $this->assertGreaterThanOrEqual(1, $result->completed);
    }

    /**
     * OutboxProcessor: failing publisher → after max_attempts exceeded → DLQ.
     *
     * We pre-set attempts to (max_attempts - 1) so the processor's single failure
     * pushes new_attempts = max_attempts, triggering the DLQ path.
     *
     * The row's max_attempts column is written by OutboxRepository::write() from the
     * DI-provided OutboxConfig (default = 5). We therefore prime the row to attempts=4
     * so the next failure hits the limit.
     */
    public function test_processor_moves_to_dlq_after_max_attempts(): void
    {
        $correlation = $this->correlation('proc_dlq');
        $event_id    = $this->outbox_repo->write($this->make_event(), $correlation);

        // Prime attempts to (max_attempts - 1) = 4 so the next failure triggers DLQ.
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$this->outbox_table}` SET attempts = 4 WHERE event_id = %s",
            $event_id
        ));

        $publisher = new class implements IOutboxPublisher {
            public function publish(OutboxEntry $entry, array $wrapped_payload): void {
                throw new \RuntimeException('publisher failure');
            }
        };

        $result = $this->make_processor($publisher)->process_batch();

        $outbox_row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_id = %s",
            $event_id
        ));

        $this->assertSame('dlq', $outbox_row->status, 'Row must be in DLQ after max_attempts exceeded');
        $this->assertGreaterThanOrEqual(1, $result->dlq, 'ProcessingResult must report at least 1 DLQ');

        // DLQ table must also have the row.
        $dlq_count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$this->dlq_table}` WHERE event_id = %s",
            $event_id
        ));
        $this->assertSame(1, $dlq_count, 'Exactly one DLQ row for the failed event');
    }
}
