<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Messenger\IFactClassResolver;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Testing\InMemoryOutboxStore;

final class MessengerFactTransportTest extends TestCase {

  private FrozenClock $clock;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
  }

  private function claim(): Claim {
    $record = new OutboxRecord('evt-1', 'widget_registered', 'txp_integration_widget_registered', 'corr', 3, 'cmd', ['id' => 'w'], $this->clock->now());
    return new Claim('evt-1', 'tok', $this->clock->now()->modify('+60 seconds'), $record, 0);
  }

  private function resolver(?string $class = 'App\\WidgetRegistered'): IFactClassResolver {
    return new class ($class) implements IFactClassResolver {
      public function __construct(private readonly ?string $class) {}
      public function classFor(Claim $claim): ?string {
        return $this->class;
      }
    };
  }

  public function test_submit_sends_one_fact_message_and_returns_the_transport_id(): void {
    $sender = new InMemoryTransport();
    $transport = new MessengerFactTransport($sender, 'txp', $this->resolver(), 'messenger.bus.default', $this->clock);
    $claim = $this->claim();
    $wrapped = IntegrationEnvelope::wrap($claim->record->payload, 'corr', 3, 'evt-1');

    $ref = $transport->submit($claim, $wrapped, $claim->record->due_at);

    $sent = $sender->getSent();
    self::assertCount(1, $sent);
    self::assertSame((string) $sent[0]->last(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)->getId(), $ref);
    $message = $sent[0]->getMessage();
    self::assertInstanceOf(IntegrationFactMessage::class, $message);
    self::assertSame('txp', $message->consumer);
    self::assertSame('evt-1', $message->eventId);
    self::assertSame('widget_registered', $message->eventType);
    self::assertSame('App\\WidgetRegistered', $message->eventClass);
    self::assertSame('txp_integration_widget_registered', $message->integrationAction);
    self::assertSame($wrapped, $message->wrappedPayload);
    self::assertSame('messenger.bus.default', $sent[0]->last(BusNameStamp::class)?->getBusName());
    self::assertNull($sent[0]->last(DelayStamp::class), 'a due fact is never delayed again (bug 3)');
  }

  public function test_a_future_due_at_is_honoured_as_an_absolute_time(): void {
    $sender = new InMemoryTransport();
    $transport = new MessengerFactTransport($sender, 'txp', $this->resolver(), null, $this->clock);
    $claim = $this->claim();

    $transport->submit($claim, [], $this->clock->now()->modify('+90 seconds'));

    self::assertSame(90_000, $sender->getSent()[0]->last(DelayStamp::class)?->getDelay());
  }

  public function test_a_submission_without_a_reference_is_a_rejection(): void {
    $sender = new class implements SenderInterface {
      public function send(Envelope $envelope): Envelope {
        return $envelope;
      }
    };
    $transport = new MessengerFactTransport($sender, 'txp', $this->resolver(), null, $this->clock);

    $this->expectException(TransportRejected::class);
    $transport->submit($this->claim(), [], $this->clock->now());
  }

  public function test_an_unknown_fact_class_is_a_rejection(): void {
    $sender = new InMemoryTransport();
    $transport = new MessengerFactTransport($sender, 'txp', $this->resolver(null), null, $this->clock);

    try {
      $transport->submit($this->claim(), [], $this->clock->now());
      self::fail('expected TransportRejected');
    } catch (TransportRejected $e) {
      self::assertStringContainsString('evt-1', $e->getMessage());
    }
    self::assertSame([], $sender->getSent());
  }

  public function test_a_non_doctrine_sender_does_not_share_the_store_connection(): void {
    $transport = new MessengerFactTransport(new InMemoryTransport(), 'txp', $this->resolver(), null, $this->clock);
    self::assertFalse($transport->sharesConnectionWith(new InMemoryOutboxStore($this->clock)));
  }
}
