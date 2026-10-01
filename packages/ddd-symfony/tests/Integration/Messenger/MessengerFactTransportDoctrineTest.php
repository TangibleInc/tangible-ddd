<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;

final class MessengerFactTransportDoctrineTest extends PostgresTestCase {

  private function doctrineTransport(Connection $dbal): DoctrineTransport {
    $config = PostgreSqlConnection::buildConfiguration('doctrine://default?queue_name=ddd_facts&table_name=sf_test_messages&auto_setup=false');
    $transport = new DoctrineTransport(new PostgreSqlConnection($config, $dbal), new PhpSerializer());
    return $transport;
  }

  protected function setUp(): void {
    parent::setUp();
    $this->db->executeStatement('DROP TABLE IF EXISTS sf_test_messages');
    $this->doctrineTransport($this->db)->setup();
  }

  private function messages(): int {
    return (int) $this->db->fetchOne('SELECT count(*) FROM sf_test_messages');
  }

  public function test_shares_the_connection_only_with_a_store_on_the_same_dbal_connection(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $same = new MessengerFactTransport($this->doctrineTransport($this->db), 'txp', new OutboxFactClassResolver($store));
    $other = new MessengerFactTransport($this->doctrineTransport($this->secondConnection()), 'txp', new OutboxFactClassResolver($store));

    self::assertTrue($same->sharesConnectionWith($store));
    self::assertFalse($other->sharesConnectionWith($store));
  }

  public function test_submit_and_accept_commit_or_roll_back_together(): void {
    $store = new DbalPostgresOutboxStore($this->db);
    $transport = new MessengerFactTransport($this->doctrineTransport($this->db), 'txp', new OutboxFactClassResolver($store));
    $boundary = new DbalTransactionBoundary($this->db);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $store->appendFact(new OutboxRecord('evt-1', 'ping_fact', 'sft_integration_ping_fact', 'c', 1, null, ['n' => 1], $now), PingFact::class);
    [$claim] = $store->claim(1, $now, 60);
    $wrapped = IntegrationEnvelope::wrap(['n' => 1], 'c', 1, 'evt-1');

    try {
      $boundary->run(function () use ($transport, $store, $claim, $wrapped) {
        $ref = $transport->submit($claim, $wrapped, $claim->record->due_at);
        $store->accept($claim, $ref);
        throw new \RuntimeException('relay dies before commit');
      });
    } catch (\RuntimeException) {
    }
    self::assertSame(0, $this->messages(), 'the Messenger insert rolled back with the accept');
    self::assertSame('pending', $this->db->fetchOne("SELECT status FROM ddd_outbox WHERE event_id = 'evt-1'"));

    $ref = $boundary->run(function () use ($transport, $store, $claim, $wrapped) {
      $ref = $transport->submit($claim, $wrapped, $claim->record->due_at);
      self::assertTrue($store->accept($claim, $ref));
      return $ref;
    });
    self::assertSame(1, $this->messages());
    self::assertSame($ref, $this->db->fetchOne("SELECT transport_ref FROM ddd_outbox WHERE event_id = 'evt-1'"));

    $received = iterator_to_array($this->doctrineTransport($this->db)->get());
    self::assertCount(1, $received);
    $message = $received[0]->getMessage();
    self::assertInstanceOf(IntegrationFactMessage::class, $message);
    self::assertSame(PingFact::class, $message->eventClass);
    self::assertSame($wrapped, $message->wrappedPayload);
  }
}
