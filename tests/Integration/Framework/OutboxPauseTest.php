<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * Relay pause — the outbox holds paused event types instead of draining them.
 *
 * set_pause(holder, selector, until) makes fetch_pending exclude that event type
 * (rows stay 'pending', untouched); a '*' selector holds everything; clear_pause
 * releases. Proves the primitive against the real DB via the real repository.
 */
final class OutboxPauseTest extends IntegrationTestCase
{
    private const CORRELATION_PREFIX = 'ddd_outbox_pause_test_';

    private IDDDConfig $config;
    private IOutboxRepository $outbox;
    private string $outbox_table;
    private string $pauses_option;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $config = \Tangible\Datastream\WordPress\DI\di()->get(IDDDConfig::class);
        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_outbox_tables($config);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $di = \Tangible\Datastream\WordPress\DI\di();
        $this->config = $di->get(IDDDConfig::class);
        $this->outbox = $di->get(IOutboxRepository::class);
        $this->outbox_table  = $this->wpdb->prefix . $this->config->prefix() . '_integration_outbox';
        $this->pauses_option = $this->config->option('outbox_pauses');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->outbox->clear_pause('t');
        $this->outbox->clear_pause('w');
        delete_option($this->pauses_option);
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$this->outbox_table}` WHERE correlation_id LIKE %s",
            self::CORRELATION_PREFIX . '%'
        ));
    }

    public function test_exact_pause_excludes_type_and_clear_restores(): void
    {
        $event_id = $this->outbox->write($this->make_event(), $this->correlation());

        $this->assertFalse($this->outbox->is_paused('outbox.test.event'));

        $this->outbox->set_pause('t', 'outbox.test.event', -1);
        $this->assertTrue($this->outbox->is_paused('outbox.test.event'));
        $this->assertFalse($this->outbox->is_paused('unrelated.event'), 'other types not paused');

        // Held: our row is not selected (and not locked).
        $this->assertNotContains($event_id, $this->fetched_ids(), 'paused type is held');

        // Released: it drains again.
        $this->outbox->clear_pause('t');
        $this->assertContains($event_id, $this->fetched_ids(), 'drains after clear');
    }

    public function test_wildcard_pause_holds_everything(): void
    {
        $this->outbox->write($this->make_event(), $this->correlation());

        $this->outbox->set_pause('w', '*', -1);
        $this->assertTrue($this->outbox->is_paused('outbox.test.event'));
        $this->assertTrue($this->outbox->is_paused('anything.at.all'));
        $this->assertSame([], $this->outbox->fetch_pending(50, 'pause-test'), 'wildcard holds all');
    }

    /** @return string[] event_ids returned by a fetch */
    private function fetched_ids(): array
    {
        return array_map(fn($e) => $e->event_id, $this->outbox->fetch_pending(50, 'pause-test'));
    }

    private function correlation(): string
    {
        return self::CORRELATION_PREFIX . substr(md5(uniqid('', true)), 0, 8);
    }

    private function make_event(): IIntegrationEvent
    {
        return new class implements IIntegrationEvent {
            public static function name(): string { return 'outbox.test.event'; }
            public static function action(): string { return 'outbox_test_action'; }
            public static function integration_action(): string { return 'outbox_test_integration_action'; }
            public function payload(): array { return []; }
            public function integration_payload(): array { return []; }
            public function delay(): int { return 0; }
            public function is_unique(): bool { return false; }
        };
    }
}
