<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Ops\MessengerFailureTransportSource;

/** D9: the Messenger failure transport as the operator view's `transport` layer. */
final class MessengerFailureTransportSourceTest extends TestCase {

  private function failed(object $message, int $retries, string $error, string $since = '2026-10-01T10:00:00Z'): Envelope {
    $stamps = [];
    for ($i = 1; $i <= $retries; $i++) {
      $stamps[] = new RedeliveryStamp($i, new \DateTimeImmutable($since . " +{$i} minutes"));
    }
    $stamps[] = new SentToFailureTransportStamp('ddd_facts');
    $stamps[] = new ErrorDetailsStamp(\DomainException::class, 0, $error);
    return new Envelope($message, $stamps);
  }

  private function transport(): ListingReceiver {
    return new ListingReceiver();
  }

  private function fact(string $eventId, string $consumer = 'txp'): IntegrationFactMessage {
    return new IntegrationFactMessage($consumer, $eventId, 'widget_registered', 'App\\WidgetRegistered', 'txp_integration_widget_registered', []);
  }

  public function test_a_failed_fact_is_an_item_with_its_attempts_error_and_messenger_repairs(): void {
    $transport = $this->transport();
    $transport->send($this->failed($this->fact('evt-1'), 4, 'smtp down'));

    $items = (new MessengerFailureTransportSource($transport, 'txp', 'ddd_failed'))->items(null, 10);

    self::assertCount(1, $items);
    $item = $items[0];
    self::assertSame(Layer::Transport, $item->layer);
    self::assertSame('txp', $item->consumer);
    self::assertSame('1', $item->key, 'the transport message id, as messenger:failed:show takes it');
    self::assertSame(5, $item->attempts, 'four retries after the first attempt');
    self::assertNull($item->budget, 'the handler budget is counted in the delivery ledger');
    self::assertStringContainsString('evt-1', (string) $item->lastError);
    self::assertStringContainsString('smtp down', (string) $item->lastError);
    self::assertEquals(new \DateTimeImmutable('2026-10-01T10:01:00Z'), $item->firstSeen);
    self::assertSame(['messenger:failed:retry ddd_failed', 'messenger:failed:remove ddd_failed'], $item->repairActions);
  }

  public function test_another_consumer_s_messages_and_other_layers_are_left_out(): void {
    $transport = $this->transport();
    $transport->send($this->failed($this->fact('evt-1', 'other'), 0, 'x'));
    $transport->send($this->failed($this->fact('evt-2'), 0, 'y'));
    $source = new MessengerFailureTransportSource($transport, 'txp', 'ddd_failed');

    self::assertCount(1, $source->items(null, 10));
    self::assertSame(1, $source->items(null, 10)[0]->attempts);
    self::assertSame([], $source->items(Layer::Relay, 10));
    self::assertSame([], $source->items(null, 0));
  }

  public function test_a_receiver_that_cannot_list_contributes_nothing(): void {
    $receiver = $this->createStub(ReceiverInterface::class);

    self::assertSame([], (new MessengerFailureTransportSource($receiver, 'txp', 'ddd_failed'))->items(null, 10));
  }

  public function test_no_failure_transport_contributes_nothing(): void {
    self::assertSame([], (new MessengerFailureTransportSource(null, 'txp', 'ddd_failed'))->items(null, 10));
  }
}

/** A listable receiver (the Doctrine failure transport's shape) that stamps ids 1, 2, ... */
final class ListingReceiver implements ListableReceiverInterface {

  /** @var list<Envelope> */
  private array $envelopes = [];

  public function send(Envelope $envelope): void {
    $this->envelopes[] = $envelope->with(new TransportMessageIdStamp((string) (count($this->envelopes) + 1)));
  }

  public function all(?int $limit = null): iterable {
    return array_slice($this->envelopes, 0, $limit);
  }

  public function find(mixed $id): ?Envelope {
    return null;
  }

  public function get(): iterable {
    return [];
  }

  public function ack(Envelope $envelope): void {}

  public function reject(Envelope $envelope): void {}
}
