<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Runtime;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;

/**
 * The relay on Postgres with the Doctrine transport on the same connection:
 * the Messenger insert and the fenced accept commit or roll back together
 * (`relay.crash-after-submit`, `relay.lease-fencing` on sf).
 */
final class RelayPostgresTest extends PostgresTestCase {

  private DbalPostgresOutboxStore $store;

  protected function setUp(): void {
    parent::setUp();
    $this->db->executeStatement('DROP TABLE IF EXISTS sf_relay_messages');
    $this->transportOn($this->db)->setup();
    $this->store = new DbalPostgresOutboxStore($this->db);
    $this->store->append_fact(new OutboxRecord('evt-1', 'ping_fact', 'sft_integration_ping_fact', 'c', 1, null, ['n' => 1],
      new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC'))), PingFact::class);
  }

  private function transportOn(Connection $c): DoctrineTransport {
    $config = PostgreSqlConnection::buildConfiguration('doctrine://default?queue_name=ddd_facts&table_name=sf_relay_messages&auto_setup=false');
    return new DoctrineTransport(new PostgreSqlConnection($config, $c), new PhpSerializer());
  }

  private function relay(ITransport $transport): Relay {
    return new Relay($this->store, $transport, new DbalTransactionBoundary($this->db), new SystemClock(), new OutboxConfig(), new NullLogger());
  }

  private function messages(): int {
    return (int) $this->db->fetchOne('SELECT count(*) FROM sf_relay_messages');
  }

  public function test_submit_and_accept_commit_together(): void {
    $report = $this->relay(new MessengerFactTransport($this->transportOn($this->db), 'sft', new OutboxFactClassResolver($this->store)))->run_once(10);

    self::assertSame(['evt-1'], $report->accepted);
    self::assertSame(1, $this->messages());
    self::assertSame('accepted', $this->db->fetchOne("SELECT status FROM ddd_outbox WHERE event_id = 'evt-1'"));
  }

  public function test_a_lost_lease_on_accept_rolls_back_the_messenger_insert(): void {
    $inner = new MessengerFactTransport($this->transportOn($this->db), 'sft', new OutboxFactClassResolver($this->store));
    $competitor = $this->secondConnection();
    // Between submit and accept another relay re-claims the row (its lease expired).
    $stealing = new class ($inner, $competitor) implements ITransport {
      public function __construct(private readonly ITransport $inner, private readonly Connection $competitor) {}
      public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
        $ref = $this->inner->submit($c, $wrappedEnvelope, $dueAt);
        $this->competitor->executeStatement("UPDATE ddd_outbox SET claim_token = 'competitor' WHERE event_id = ?", [$c->event_id]);
        return $ref;
      }
      public function shares_connection(IOutboxStore $store): bool {
        return $this->inner->shares_connection($store);
      }
    };

    $report = $this->relay($stealing)->run_once(10);

    self::assertSame(['evt-1'], $report->lost);
    self::assertSame([], $report->accepted);
    self::assertSame(0, $this->messages(), 'no duplicate message: the losing relay rolled its insert back');
    self::assertSame('competitor', $this->db->fetchOne("SELECT claim_token FROM ddd_outbox WHERE event_id = 'evt-1'"));
  }

  public function test_a_crash_between_submit_and_accept_leaves_one_pending_row_and_no_message(): void {
    $inner = new MessengerFactTransport($this->transportOn($this->db), 'sft', new OutboxFactClassResolver($this->store));
    $crashing = new class ($inner) implements ITransport {
      public function __construct(private readonly ITransport $inner) {}
      public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
        $this->inner->submit($c, $wrappedEnvelope, $dueAt);
        throw new \RuntimeException('relay process dies after the Messenger insert');
      }
      public function shares_connection(IOutboxStore $store): bool {
        return $this->inner->shares_connection($store);
      }
    };

    $this->relay($crashing)->run_once(10);

    self::assertSame(0, $this->messages());
    self::assertSame('pending', $this->db->fetchOne("SELECT status FROM ddd_outbox WHERE event_id = 'evt-1'"));
  }
}
