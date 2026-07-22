<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use Tangible\Datastream\Application\Commands\RecordCapturedEventCommand;
use Tangible\Datastream\Domain\Events\EventCaptured;
use Tangible\Datastream\Infra\DatastreamConfig;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\DomainEventAfterSealException;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeFatMoment;
use TangibleDDD\Tests\Fakes\FakeTwinEvent;
use TangibleDDD\Tests\Integration\CommandIntegrationTestCase;

/**
 * E2E — event seal semantics through a real consumer dispatch.
 *
 * The 0.6.1 seal repair keyed the post-handler seal on IAnnouncesIntegration
 * (the raisable "announces a fact" marker) instead of IIntegrationEvent (the
 * scalar record contract, severed from IDomainEvent at the 0.2.0 split). This
 * lane proves both halves of that contract through datastream's real container
 * and command bus — not a bare EventsUnitOfWork instance:
 *
 *   1. A synchronous domain-event listener that records an announcer DURING
 *      the sealed drain survives the seal, and the announcer's twin is routed
 *      to the integration outbox stamped with the act's story (correlation_id)
 *      and raiser edge (command_id). This is exactly the "fact announced from
 *      a sealed drain" pattern the LMS self-publisher migration relies on.
 *
 *   2. A plain, non-integrable domain event recorded past the seal throws
 *      DomainEventAfterSealException.
 *
 * Extends CommandIntegrationTestCase: RecordCapturedEventCommand is
 * ITransactionalCommand (inner START TRANSACTION / COMMIT), so cleanup is
 * discriminator-scoped DELETEs, not an outer rollback.
 */
final class EventSealE2ETest extends CommandIntegrationTestCase
{
    private const TEST_SOURCE = 'tangible_ddd_seal_e2e_test';

    private \wpdb  $wpdb;
    private string $captured_table;
    private string $outbox_table;

    /** @var callable|null listener added in a test, removed in tearDown */
    private $listener = null;

    // ── Schema setup (DDL auto-commits; once per class) ───────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        global $wpdb;

        require_once dirname(__DIR__, 3) . '/.reference/tangible-datastream/includes/database/captured-events.php';
        tangible_datastream_install_captured_events_table(new DatastreamConfig($wpdb->prefix));

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_outbox_tables(
            \Tangible\Datastream\WordPress\DI\di()->get(IDDDConfig::class)
        );
    }

    // ── Per-test setup / teardown ─────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        $config = new DatastreamConfig($this->wpdb->prefix);
        $this->captured_table = $config->table('captured_events');

        $ddd_config = \Tangible\Datastream\WordPress\DI\di()->get(IDDDConfig::class);
        $this->outbox_table = $this->wpdb->prefix . $ddd_config->prefix() . '_integration_outbox';
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            remove_action(EventCaptured::action(), $this->listener);
            $this->listener = null;
        }

        $this->cleanup_rows($this->captured_table, 'source', self::TEST_SOURCE);
        $this->cleanup_rows($this->outbox_table, 'event_type', FakeTwinEvent::name());

        parent::tearDown();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * A listener on the drained domain event records an announcer (fat moment)
     * into the already-sealed UoW. The drain loop must flush it, EventRouter
     * must route its twin to the outbox, and the row must carry the act's
     * correlation_id and command_id (the raiser edge at rest).
     */
    public function test_announcer_recorded_from_sealed_drain_reaches_outbox_with_story_and_raiser(): void
    {
        $uow = \Tangible\Datastream\WordPress\DI\di()->get(EventsUnitOfWork::class);

        $in_act_correlation = null;
        $in_act_cause       = null;

        $this->listener = function () use ($uow, &$in_act_correlation, &$in_act_cause): void {
            // Snapshot the ambient act scope: the outbox row must carry these.
            $ctx = Correlation::current();
            $in_act_correlation = $ctx->correlation_id;
            $in_act_cause       = $ctx->cause;

            // Recording (not dispatching) an announcer inside the sealed drain
            // is the sanctioned pattern — the seal must let it through.
            $uow->record(new FakeFatMoment((object) ['id' => 9]));
        };
        add_action(EventCaptured::action(), $this->listener, 10, 0);

        (new RecordCapturedEventCommand(
            source:   self::TEST_SOURCE,
            event:    'seal_probe',
            actor_id: 1,
            fields:   [],
        ))->send();

        // The listener ran inside an ACT scope.
        $this->assertNotNull($in_act_correlation, 'Listener must observe an ambient correlation');
        $this->assertNotNull($in_act_cause, 'Listener must observe the act as ambient cause');
        $this->assertSame(Kind::Act, $in_act_cause->kind);

        // The announcer's twin reached the outbox…
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE event_type = %s ORDER BY id DESC LIMIT 1",
            FakeTwinEvent::name(),
        ));
        $this->assertNotNull($row, 'The post-seal announcer\'s twin must be routed to the outbox');

        // …stamped with the same story and the act as raiser.
        $this->assertSame($in_act_correlation, $row->correlation_id, 'Outbox row must carry the act\'s story');
        $this->assertSame($in_act_cause->id, $row->command_id, 'Outbox row must carry the act as raiser edge');
    }

    /**
     * The command pipeline leaves the UoW sealed after dispatch. A plain
     * domain event recorded past the seal must throw.
     */
    public function test_plain_domain_event_recorded_after_seal_throws(): void
    {
        (new RecordCapturedEventCommand(
            source:   self::TEST_SOURCE,
            event:    'seal_probe_plain',
            actor_id: 1,
            fields:   [],
        ))->send();

        $this->expectException(DomainEventAfterSealException::class);

        \Tangible\Datastream\WordPress\DI\di()
            ->get(EventsUnitOfWork::class)
            ->record(new FakeDomainEvent());
    }
}
