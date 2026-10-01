<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;

/**
 * IRelayWakeup (D14) as a transactional Postgres NOTIFY on the writer's
 * connection: `SELECT pg_notify('ddd_relay_<prefix>', '<prefix>')`.
 *
 * Inside a transaction Postgres delivers the notification at COMMIT and
 * drops it on ROLLBACK, so a poke made in the same transaction as an outbox
 * append or a wakeup intent wakes the relay exactly when the row becomes
 * visible ("after commit", register 3.5). Postgres folds identical
 * notifications of one transaction into one. Outside a transaction it is
 * delivered at once.
 *
 * A poke only shortens latency: the relay also polls (5.3). A failing poke
 * is logged and swallowed, never failing the business transaction. Note that
 * a statement error inside a transaction aborts it; pg_notify on a healthy
 * connection does not fail in practice.
 */
final class PostgresNotifyRelayWakeup implements IRelayWakeup {

  private readonly LoggerInterface $logger;

  public function __construct(private readonly Connection $connection, ?LoggerInterface $logger = null) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function poke(string $consumerPrefix): void {
    try {
      $this->connection->executeStatement('SELECT pg_notify(?, ?)', [self::channel($consumerPrefix), $consumerPrefix]);
    } catch (\InvalidArgumentException $e) {
      throw $e;
    } catch (\Throwable $e) {
      $this->logger->warning("[ddd relay] relay wakeup NOTIFY failed (the relay's poll will pick the row up): {$e->getMessage()}");
    }
  }

  /** The LISTEN/NOTIFY channel of one consumer. */
  public static function channel(string $consumerPrefix): string {
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $consumerPrefix)) {
      throw new \InvalidArgumentException("Consumer prefix '$consumerPrefix' must match [a-z0-9_]{1,40} to name a NOTIFY channel");
    }
    return 'ddd_relay_' . $consumerPrefix;
  }
}
