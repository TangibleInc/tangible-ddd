<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Runtime;

use Doctrine\DBAL\DriverManager;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresListenWaiter;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresNotifyRelayWakeup;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/**
 * D14 on Postgres 16: IRelayWakeup as a transactional NOTIFY on the writer's
 * connection, and a LISTEN-based waiter on a worker's (second) connection.
 * The notify is delivered at COMMIT only; a rollback delivers nothing; the
 * waiter falls back to its timeout (the poll fallback).
 */
final class PostgresNotifyWakeupTest extends PostgresTestCase {

  public function test_a_poke_outside_a_transaction_wakes_a_listener_on_another_connection(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    $waiter->listen();

    (new PostgresNotifyRelayWakeup($this->db))->poke('acme');

    self::assertTrue($waiter->wait(2.0));
    self::assertSame(['acme'], $waiter->payloads());
  }

  public function test_a_poke_inside_a_transaction_is_delivered_at_commit_only(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    $waiter->listen();
    $wakeup = new PostgresNotifyRelayWakeup($this->db);

    $this->db->beginTransaction();
    $wakeup->poke('acme');
    self::assertFalse($waiter->wait(0.2), 'not delivered before COMMIT');
    $this->db->commit();

    self::assertTrue($waiter->wait(2.0));
  }

  public function test_a_rolled_back_poke_is_never_delivered(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    $waiter->listen();

    $this->db->beginTransaction();
    (new PostgresNotifyRelayWakeup($this->db))->poke('acme');
    $this->db->rollBack();

    self::assertFalse($waiter->wait(0.3));
  }

  public function test_without_a_notification_the_waiter_returns_false_after_its_timeout(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');

    $started = microtime(true);
    self::assertFalse($waiter->wait(0.3));
    $elapsed = microtime(true) - $started;

    self::assertGreaterThanOrEqual(0.25, $elapsed);
    self::assertLessThan(2.0, $elapsed);
  }

  public function test_pokes_for_another_consumer_do_not_wake_the_waiter(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    $waiter->listen();

    (new PostgresNotifyRelayWakeup($this->db))->poke('globex');

    self::assertFalse($waiter->wait(0.3));
  }

  public function test_several_pokes_are_drained_by_one_wait(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    $waiter->listen();
    $wakeup = new PostgresNotifyRelayWakeup($this->db);

    $this->db->beginTransaction();
    $wakeup->poke('acme');
    $wakeup->poke('acme');
    $this->db->commit();
    $wakeup->poke('acme');

    self::assertTrue($waiter->wait(2.0));
    usleep(100_000);
    $waiter->wait(0.0);
    self::assertFalse($waiter->wait(0.2), 'every pending notification was consumed');
  }

  public function test_the_first_wait_listens_by_itself(): void {
    $waiter = new PostgresListenWaiter($this->secondConnection(), 'acme');
    self::assertFalse($waiter->wait(0.0)); // LISTEN happens here

    (new PostgresNotifyRelayWakeup($this->db))->poke('acme');

    self::assertTrue($waiter->wait(2.0));
  }

  public function test_the_channel_is_derived_from_the_consumer_prefix(): void {
    self::assertSame('ddd_relay_acme', PostgresNotifyRelayWakeup::channel('acme'));
    $this->expectException(\InvalidArgumentException::class);
    PostgresNotifyRelayWakeup::channel('acme; DROP');
  }

  public function test_the_listen_waiter_refuses_a_pooled_connection_when_told_to(): void {
    $pooled = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'db', 'port' => 6432, 'dbname' => 'd', 'user' => 'u']);

    $this->expectException(PooledConnectionRefused::class);
    new PostgresListenWaiter($pooled, 'acme', null, PoolerPolicy::Refuse);
  }
}
