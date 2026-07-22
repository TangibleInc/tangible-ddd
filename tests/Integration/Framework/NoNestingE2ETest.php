<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use Tangible\Datastream\Application\Commands\RecordCapturedEventCommand;
use Tangible\Datastream\Domain\Events\EventCaptured;
use Tangible\Datastream\Infra\DatastreamConfig;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Tests\Integration\CommandIntegrationTestCase;

/**
 * E2E — the no-nesting invariant (0.2.4) through a real consumer dispatch.
 *
 * Acts are the atomic moments and never nest: a synchronous domain-event
 * listener that dispatches a command IN-BAND (inside the enclosing act's
 * drain) must fatal with CommandDispatchedInsideCommand. This is precisely
 * the pattern that made pre-migration LMS/datastream captures dangerous —
 * the lane pins it so a consumer regressing into in-band dispatch fails
 * loudly here, not silently in production.
 *
 * (The sanctioned alternatives: record an announcer into the UoW, or defer
 *  the dispatch to the integration lane via integration_action()'s for_fact
 *  scope — see EventSealE2ETest for the former.)
 */
final class NoNestingE2ETest extends CommandIntegrationTestCase
{
    private const TEST_SOURCE = 'tangible_ddd_nonesting_e2e_test';

    private \wpdb  $wpdb;
    private string $captured_table;

    /** @var callable|null listener added in the test, removed in tearDown */
    private $listener = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        global $wpdb;

        require_once dirname(__DIR__, 3) . '/.reference/tangible-datastream/includes/database/captured-events.php';
        tangible_datastream_install_captured_events_table(new DatastreamConfig($wpdb->prefix));
    }

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        $this->captured_table = (new DatastreamConfig($this->wpdb->prefix))->table('captured_events');
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            remove_action(EventCaptured::action(), $this->listener);
            $this->listener = null;
        }

        // The outer act may have committed its row before the drain threw.
        $this->cleanup_rows($this->captured_table, 'source', self::TEST_SOURCE);

        parent::tearDown();
    }

    public function test_in_band_dispatch_inside_an_act_throws(): void
    {
        $this->listener = static function (): void {
            // A consumer listener reacting to a domain event by dispatching a
            // command in-band — the forbidden nest.
            (new RecordCapturedEventCommand(
                source:   self::TEST_SOURCE,
                event:    'nested_capture',
                actor_id: 2,
                fields:   [],
            ))->send();
        };
        add_action(EventCaptured::action(), $this->listener, 10, 0);

        $this->expectException(CommandDispatchedInsideCommand::class);

        (new RecordCapturedEventCommand(
            source:   self::TEST_SOURCE,
            event:    'outer_capture',
            actor_id: 1,
            fields:   [],
        ))->send();
    }
}
