<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use Tangible\Datastream\Application\Commands\RecordCapturedEventCommand;
use Tangible\Datastream\Infra\DatastreamConfig;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Intents\Intents;
use TangibleDDD\Application\Intents\IntentsMiddleware;
use TangibleDDD\Application\Intents\UndecidedIntents;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Persistence\TransactionMiddleware;
use TangibleDDD\Tests\Fakes\Intents\FakeTransactionalOuterCommand;
use TangibleDDD\Tests\Integration\CommandIntegrationTestCase;

use function TangibleDDD\WordPress\ddd_maybe_migrate;
use function TangibleDDD\WordPress\ddd_schema_version_key;

/**
 * E2E — captured intents through real WordPress + MySQL.
 *
 * The outer act runs on a bus composed here in the framework's order
 * (Correlation -> Transaction -> Intents -> handler): the reference
 * datastream bus has no IntentsMiddleware, consumers add it themselves.
 * Its handler wraps a WordPress action in Intents::capture(); a listener on
 * that action sends a real datastream command through datastream's OWN bus,
 * whose CorrelationMiddleware sees the open act and captures the send
 * instead of throwing CommandDispatchedInsideCommand.
 */
final class CapturedIntentsE2ETest extends CommandIntegrationTestCase
{
    private const TEST_SOURCE = 'tangible_ddd_captured_intents_e2e_test';
    private const ACTION      = 'tangible_ddd_intents_e2e';

    /** file:line of the listener's send(), set when the listener runs. */
    private static ?string $send_site = null;

    private \wpdb $wpdb;
    private DatastreamConfig $config;
    private CommandBus $bus;

    /** @var callable|null the act's handler, run by the bus's terminal middleware */
    private $handler = null;

    /** @var callable|null listener added in the test, removed in tearDown */
    private $listener = null;

    private ?string $act_id = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        global $wpdb;

        require_once dirname(__DIR__, 3) . '/.reference/tangible-datastream/includes/database/captured-events.php';
        tangible_datastream_install_captured_events_table(new DatastreamConfig($wpdb->prefix));

        ddd_maybe_migrate(new DatastreamConfig($wpdb->prefix));
    }

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->config = new DatastreamConfig($wpdb->prefix);

        self::$send_site = null;
        $this->act_id    = null;

        $terminal = new class(fn(object $command) => ($this->handler)($command)) implements Middleware {
            public function __construct(private \Closure $handle) {}

            public function execute($command, callable $next)
            {
                return ($this->handle)($command);
            }
        };

        $this->bus = new CommandBus(
            new CorrelationMiddleware(
                $this->config,
                \Tangible\Datastream\WordPress\DI\di()->get(EventsUnitOfWork::class),
                new Redactor(),
            ),
            new TransactionMiddleware($this->wpdb),
            new IntentsMiddleware(),
            $terminal,
        );

        $this->listener = static function (): void {
            $command = new RecordCapturedEventCommand(
                source:   self::TEST_SOURCE,
                event:    'foreign_send',
                actor_id: 1,
                fields:   [],
            );
            // The origin is relative to ABSPATH: the plugin lives under it.
            self::$send_site = 'wp-content/plugins/' . basename(dirname(__DIR__, 3))
                . '/tests/Integration/Framework/' . basename(__FILE__) . ':' . (__LINE__ + 1);
            $command->send();
        };
        add_action(self::ACTION, $this->listener, 10, 0);
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            remove_action(self::ACTION, $this->listener);
            $this->listener = null;
        }

        // Only an inner send that escaped capture would write this row.
        $this->cleanup_rows($this->config->table('captured_events'), 'source', self::TEST_SOURCE);
        if ($this->act_id !== null) {
            $this->cleanup_rows($this->config->table('command_audit'), 'command_id', $this->act_id);
        }

        parent::tearDown();
    }

    public function test_foreign_send_is_captured_rejected_and_audited(): void
    {
        $this->handler = function (): void {
            $this->act_id = Correlation::current()->cause->id;

            $captured = Intents::capture(fn() => do_action(self::ACTION));
            $captured->reject('e2e reject');
        };

        $this->bus->handle(new FakeTransactionalOuterCommand());

        $this->assertNotNull($this->act_id, 'The handler must have run');
        $this->assertNotNull(self::$send_site, 'The listener must have sent');

        $row = $this->audit_row($this->act_id);
        $this->assertNotNull($row, 'The outer act must have an audit row');
        $this->assertSame('success', $row->status);
        $this->assertSame(
            [[
                'command'  => RecordCapturedEventCommand::class,
                'origin'   => self::$send_site,
                'decision' => 'rejected',
                'reason'   => 'e2e reject',
            ]],
            json_decode((string) $row->intents, true),
        );

        // The inner command never became an act: no audit row in the story
        // besides the outer one, and its handler never wrote.
        $this->assertSame(
            '0',
            $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->config->table('command_audit')}` WHERE correlation_id = %s AND command_name = %s",
                $row->correlation_id,
                RecordCapturedEventCommand::class,
            )),
            'The captured command must have no audit row',
        );
        $this->assertSame('0', $this->captured_event_count(), 'The captured command must not have run');

        $this->assertSame(8, (int) get_option(ddd_schema_version_key($this->config)));
        $this->assertSame(
            '1',
            $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $this->config->table('command_audit'),
                'intents',
            )),
            'The v8 migration must have added command_audit.intents',
        );
    }

    public function test_undecided_foreign_send_fails_the_act(): void
    {
        $this->handler = function (): void {
            $this->act_id = Correlation::current()->cause->id;

            Intents::capture(fn() => do_action(self::ACTION));
        };

        try {
            $this->bus->handle(new FakeTransactionalOuterCommand());
            $this->fail('An act that leaves an intent undecided must throw UndecidedIntents');
        } catch (UndecidedIntents $e) {
            $this->assertStringContainsString(RecordCapturedEventCommand::class, $e->getMessage());
        }

        $row = $this->audit_row((string) $this->act_id);
        $this->assertNotNull($row, 'The failed act must still have an audit row');
        $this->assertSame('error', $row->status);
        $this->assertSame('undecided', json_decode((string) $row->intents, true)[0]['decision']);
        $this->assertSame('0', $this->captured_event_count(), 'The captured command must not have run');
    }

    private function audit_row(string $command_id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->config->table('command_audit')}` WHERE command_id = %s",
            $command_id,
        ));
    }

    private function captured_event_count(): string
    {
        return (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$this->config->table('captured_events')}` WHERE source = %s",
            self::TEST_SOURCE,
        ));
    }
}
