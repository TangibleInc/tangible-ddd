<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use Tangible\Datastream\Application\Commands\RecordCapturedEventCommand;
use Tangible\Datastream\Domain\Events\EventCaptured;
use Tangible\Datastream\Domain\Repositories\ICapturedEventRepository;
use Tangible\Datastream\Infra\DatastreamConfig;
use TangibleDDD\Tests\Integration\CommandIntegrationTestCase;

/**
 * E2E-1 — CommandBus real-DB round-trip.
 *
 * Drives the full command-bus pipeline:
 *   RecordCapturedEventCommand → TransactionMiddleware (START TX / COMMIT)
 *     → DomainEventsPublishMiddleware (drain UoW, dispatch WP action)
 *       → RecordCapturedEventHandler → CapturedEventRepository (INSERT)
 *
 * Assertions:
 *   1. captured_events row persisted with correct column values.
 *   2. fields JSON round-trips correctly.
 *   3. EventCaptured WP action fired (domain event published).
 *   4. Repository can reconstitute the aggregate from the persisted row.
 *
 * Extends CommandIntegrationTestCase because RecordCapturedEventCommand
 * implements ITransactionalCommand, causing TransactionMiddleware to issue
 * its own START TRANSACTION / COMMIT — incompatible with the outer-transaction
 * wrap in IntegrationTestCase. tearDown() deletes rows via cleanup_rows().
 */
final class CommandBusE2ETest extends CommandIntegrationTestCase
{
    private \wpdb  $wpdb;
    private string $table;

    /** Discriminator used to scope DELETE in tearDown. */
    private const TEST_SOURCE = 'tangible_ddd_e2e_test';

    // ── Schema setup (runs once per class; DDL auto-commits in MySQL) ─────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        global $wpdb;

        require_once dirname(__DIR__, 3) . '/.reference/tangible-datastream/includes/database/captured-events.php';

        $config = new DatastreamConfig($wpdb->prefix);
        tangible_datastream_install_captured_events_table($config);
    }

    // ── Per-test setup ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp(); // registers rules, boots DI, resets singletons

        global $wpdb;
        $this->wpdb = $wpdb;

        $config      = new DatastreamConfig($this->wpdb->prefix);
        $this->table = $config->table('captured_events');
    }

    // ── Per-test teardown ─────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        // Delete rows written by this test class (scoped by source discriminator).
        $this->cleanup_rows($this->table, 'source', self::TEST_SOURCE);

        parent::tearDown(); // resets UoW + Correlation facade
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Full command-bus E2E:
     * - Row persisted in captured_events with correct values.
     * - EventCaptured WP action fired.
     * - Repository reconstitutes the aggregate from the persisted row.
     */
    public function test_record_captured_event_persists_row_and_publishes_domain_event(): void
    {
        // ── Spy: hook onto the WP action EventCaptured dispatches ─────────────
        $spy_fired   = false;
        $spy_payload = null;

        add_action(
            EventCaptured::action(),
            function (
                string $source,
                string $event,
                int    $actor_id,
                array  $fields,
                string $occurred_at,
                ?int   $captured_event_id = null,
            ) use (&$spy_fired, &$spy_payload) {
                $spy_fired   = true;
                $spy_payload = compact('source', 'event', 'actor_id', 'fields', 'occurred_at', 'captured_event_id');
            },
            10,
            6, // source, event, actor_id, fields, occurred_at, captured_event_id
        );

        // ── Dispatch the command through the full pipeline ────────────────────
        (new RecordCapturedEventCommand(
            source:   self::TEST_SOURCE,
            event:    'course_completed',
            actor_id: 42,
            fields:   ['course_id' => 7, 'score' => 100],
        ))->send();

        // ── Assert 1: row persisted ───────────────────────────────────────────
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE source = %s ORDER BY id DESC LIMIT 1",
                self::TEST_SOURCE,
            ),
        );

        $this->assertNotNull($row, 'A captured_events row must have been inserted after dispatch');
        $this->assertSame(self::TEST_SOURCE, $row->source);
        $this->assertSame('course_completed', $row->event);
        $this->assertSame(42, (int) $row->actor_id);

        // ── Assert 2: fields JSON round-trip ─────────────────────────────────
        $decoded_fields = json_decode($row->fields, true);
        $this->assertSame(['course_id' => 7, 'score' => 100], $decoded_fields);

        // ── Assert 3: EventCaptured domain event was published ────────────────
        $this->assertTrue($spy_fired, 'EventCaptured WP action must fire after command dispatch');
        $this->assertNotNull($spy_payload);
        $this->assertSame(self::TEST_SOURCE, $spy_payload['source']);
        $this->assertSame('course_completed', $spy_payload['event']);
        $this->assertSame(42, $spy_payload['actor_id']);

        // ── Assert 4: repository reconstitutes the aggregate ──────────────────
        $repo     = \Tangible\Datastream\WordPress\DI\di()->get(ICapturedEventRepository::class);
        $id       = (int) $row->id;
        $reloaded = $repo->get_by_id($id);

        $this->assertNotNull($reloaded, 'Repository must reconstitute the aggregate by ID');
        $this->assertSame(self::TEST_SOURCE, $reloaded->source());
        $this->assertSame('course_completed', $reloaded->event_name());
        $this->assertSame(42, $reloaded->actor_id());
        $this->assertSame(['course_id' => 7, 'score' => 100], $reloaded->fields());
    }
}
