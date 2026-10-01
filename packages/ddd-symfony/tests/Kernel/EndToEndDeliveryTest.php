<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\AnyWidgetFactListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\WidgetRegisteredListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Reactions\CountRegistrations;

/**
 * The round-1 acceptance path: command → DBAL transaction → outbox row →
 * `ddd:relay --once` → Messenger `ddd_facts` → delivery handler → listener
 * command, and the listener runs exactly once when the message is delivered
 * twice (ledger).
 */
final class EndToEndDeliveryTest extends KernelTestBase {

  public function test_command_to_outbox_to_relay_to_listener_exactly_once_under_redelivery(): void {
    // 1. dispatch through the core pipeline, inside a DBAL transaction
    (new RegisterWidgetCommand('w1', 'Widget One'))->send();

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame('Widget One', $this->db->fetchOne("SELECT name FROM app_widgets WHERE id = 'w1'"));
    $row = $this->db->fetchAssociative('SELECT * FROM ddd_outbox');
    self::assertSame('pending', $row['status']);
    self::assertSame('widget_registered', $row['event_type']);
    self::assertSame(WidgetRegistered::class, $row['event_class']);
    self::assertSame('sfk_integration_widget_registered', $row['integration_action']);
    self::assertNotNull($row['command_id'], 'raiser edge: the act that announced it');
    self::assertSame(['class:w1', 'marker:w1'], CountRegistrations::$seen, 'in-transaction reactions ran');
    self::assertSame(0, WidgetRegisteredListener::$constructed, 'listeners are not built at boot or dispatch');
    $eventId = $row['event_id'];

    // 2. relay: submit + accept in one transaction on the shared connection
    $transport = self::getContainer()->get('tangible_ddd.fact_transport');
    self::assertTrue($transport->shares_connection(self::getContainer()->get('tangible_ddd.outbox_store')));
    $relay = $this->console('ddd:relay', ['--once' => true]);
    self::assertSame(0, $relay->getStatusCode());
    self::assertStringContainsString('accepted 1', $relay->getDisplay());
    self::assertSame('accepted', $this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$eventId]));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"));

    // 3. consume with the delivery handler
    $consume = $this->console('messenger:consume', ['receivers' => ['ddd_facts'], '--limit' => 1, '--time-limit' => 10]);
    self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'registered' AND widget_id = 'w1'"));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'any-widget-fact'"), 'D2 marker listener');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'), 'acked');
    self::assertSame(3, $this->countRows('SELECT count(*) FROM ddd_delivery_ledger WHERE event_id = ? AND delivered_at IS NOT NULL', [$eventId]), 'three listeners');
    self::assertSame(1, WidgetRegisteredListener::$constructed);

    // 4. the same fact delivered again (a redelivery / duplicate message)
    $wrapped = IntegrationEnvelope::wrap(['widget_id' => 'w1'], $row['correlation_id'], (int) $row['sequence'], $eventId);
    self::getContainer()->get('messenger.transport.ddd_facts')->send(new Envelope(
      new IntegrationFactMessage('sfk', $eventId, 'widget_registered', WidgetRegistered::class, 'sfk_integration_widget_registered', $wrapped),
      [new BusNameStamp('messenger.bus.default')],
    ));
    $again = $this->console('messenger:consume', ['receivers' => ['ddd_facts'], '--limit' => 1, '--time-limit' => 10]);
    self::assertSame(0, $again->getStatusCode(), $again->getDisplay() . $again->getErrorOutput());

    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'registered'"), 'listener ran exactly once');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'any-widget-fact'"));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'));

    // 5. the worker left no runtime state behind
    self::assertNull(Correlation::peek());
    self::assertSame([], self::getContainer()->get(EventsUnitOfWork::class)->drain());
  }

  public function test_a_failing_handler_rolls_back_the_domain_row_and_the_outbox_row(): void {
    try {
      (new RegisterWidgetCommand('w2', 'x', failAfterWrite: true))->send();
      self::fail('expected the handler exception');
    } catch (\DomainException $e) {
      self::assertSame('handler failed after writing', $e->getMessage());
    }

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(0, $this->countRows('SELECT count(*) FROM app_widgets'));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_outbox'));
  }
}
