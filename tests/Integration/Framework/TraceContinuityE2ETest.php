<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Tests\Fakes\FakeTwinEvent;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * E2E — trace continuity across the outbox (the cross-request half of
 * causality tracing).
 *
 * The story must survive two hand-offs, and identity never sits on the fact:
 *
 *   act scope → outbox ROW      (at-rest: correlation_id + command_id raiser,
 *                                stamped by OutboxIntegrationEventBus)
 *   outbox row → wire ENVELOPE  (in-flight: __correlation_id/__sequence/
 *                                __event_id smeared by IntegrationEnvelope::wrap
 *                                in the OutboxProcessor)
 *   envelope → wake scope       (derived: trace_context()->for_fact(event_id))
 *
 * Runs through datastream's real container (real bus binding, real repository,
 * real MySQL rows) with a spy IOutboxPublisher standing in for the transport.
 *
 * tearDown deletes by correlation discriminator (fetch_pending()'s internal
 * transaction commits the outer wrap — same caveat as OutboxPrimitiveTest).
 */
final class TraceContinuityE2ETest extends IntegrationTestCase
{
    private const CORRELATION_PREFIX = 'ddd_trace_e2e_test_';

    private IOutboxRepository $outbox_repo;
    private string $outbox_table;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_outbox_tables(
            \Tangible\Datastream\WordPress\DI\di()->get(IDDDConfig::class)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $di = \Tangible\Datastream\WordPress\DI\di();
        $this->outbox_repo = $di->get(IOutboxRepository::class);

        $config = $di->get(IDDDConfig::class);
        $this->outbox_table = $this->wpdb->prefix . $config->prefix() . '_integration_outbox';

        Correlation::reset();
    }

    protected function tearDown(): void
    {
        Correlation::reset();

        parent::tearDown();

        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$this->outbox_table}` WHERE correlation_id LIKE %s",
            self::CORRELATION_PREFIX . '%'
        ));
    }

    /**
     * Publish a fact from inside an act-shaped scope through the container's
     * real IIntegrationEventBus, then drain it with the real OutboxProcessor:
     *
     *  - the ROW carries the act's story and the act as raiser (at rest);
     *  - the ENVELOPE carries the same story + the row's event_id (in flight);
     *  - the derived wake context resumes the SAME story with the fact as cause.
     */
    public function test_story_survives_act_to_row_to_envelope_to_wake_context(): void
    {
        $correlation = self::CORRELATION_PREFIX . substr(md5(uniqid('', true)), 0, 8);
        $command_id  = bin2hex(random_bytes(16));

        // ── Act side: announce a fact from within an act scope ────────────────
        $bus = \Tangible\Datastream\WordPress\DI\di()->get(IIntegrationEventBus::class);

        Correlation::within(
            (new TraceContext($correlation))->for_act($command_id, 'trace-e2e-probe'),
            static fn() => $bus->publish(new FakeTwinEvent(entity_id: 5)),
        );

        // ── At rest: row stamped with story + raiser ───────────────────────────
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->outbox_table}` WHERE correlation_id = %s",
            $correlation,
        ));

        $this->assertNotNull($row, 'publish() from an act scope must write an outbox row');
        $this->assertSame($correlation, $row->correlation_id);
        $this->assertSame($command_id, $row->command_id, 'The act is the raiser edge, at rest on the row');
        $this->assertNotEmpty($row->event_id, 'The fact\'s at-rest identity is the outbox row');

        // ── Drain side: real processor, spy transport ──────────────────────────
        $spy = new class implements IOutboxPublisher {
            public array $wrapped = [];
            public function publish(OutboxEntry $entry, array $wrapped_payload): void {
                $this->wrapped[$entry->event_id] = $wrapped_payload;
            }
        };

        $di = \Tangible\Datastream\WordPress\DI\di();
        (new OutboxProcessor(
            $di->get(IDDDConfig::class),
            $this->outbox_repo,
            new OutboxConfig(batch_size: 10, max_attempts: 3),
            $spy,
        ))->process_batch();

        $this->assertArrayHasKey($row->event_id, $spy->wrapped, 'The processor must hand our row to the transport');

        // ── In flight: the envelope carries the same story ─────────────────────
        $envelope = IntegrationEnvelope::unwrap($spy->wrapped[$row->event_id]);

        $this->assertSame($correlation, $envelope->correlation_id, 'The envelope must carry the row\'s story');
        $this->assertSame($row->event_id, $envelope->event_id, 'The envelope must carry the fact\'s identity');

        // ── Wake side: the derived context resumes the story, fact as cause ────
        $wake = $envelope->trace_context()?->for_fact($envelope->event_id, FakeTwinEvent::name());

        $this->assertNotNull($wake, 'A journey-carrying envelope must derive a wake context');
        $this->assertSame($correlation, $wake->correlation_id, 'The wake scope continues the SAME story');
        $this->assertSame(Kind::Fact, $wake->cause?->kind, 'In the wake, the fact is the cause');
        $this->assertSame($row->event_id, $wake->cause?->id);
    }
}
