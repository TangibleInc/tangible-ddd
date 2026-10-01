<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Persistence\ConnectionTopology;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;

/**
 * The worker side of D14: `LISTEN ddd_relay_<prefix>` on a DIRECT connection
 * and wait for a notification with PDO's pgsqlGetNotify(timeout), so an idle
 * `ddd:relay` reacts to a commit at once instead of on its next poll.
 *
 * - listen() is idempotent and is also done by the first wait().
 * - wait($seconds) returns true as soon as at least one notification for
 *   the channel is pending (and drains every pending one), false after the
 *   timeout. With a non-PDO native connection, or after a LISTEN error, it
 *   degrades to a plain sleep (the poll fallback) and logs once.
 * - A reconnect loses the LISTEN; the next wait() listens again (DBAL
 *   reconnects lazily), and anything committed in between is found by the
 *   poll.
 *
 * LISTEN does not survive a transaction pooler: on a connection that looks
 * pooled it warns (Warn) or throws PooledConnectionRefused (Refuse).
 */
final class PostgresListenWaiter implements IRelayWaiter {

  private bool $listening = false;

  private ?object $listenedOn = null;

  private bool $degraded = false;

  /** @var list<string> */
  private array $payloads = [];

  private readonly LoggerInterface $logger;

  private readonly string $channel;

  public function __construct(
    private readonly Connection $connection,
    string $consumerPrefix,
    ?LoggerInterface $logger = null,
    PoolerPolicy $pooler = PoolerPolicy::Warn,
  ) {
    $this->logger = $logger ?? new NullLogger();
    $this->channel = PostgresNotifyRelayWakeup::channel($consumerPrefix);

    $why = ConnectionTopology::pooler($connection->getParams());
    if ($why !== null) {
      $message = "[ddd relay] the LISTEN connection looks pooled ($why); LISTEN needs a direct (non-pooled) connection (register 5.2, D14)";
      if ($pooler === PoolerPolicy::Refuse) {
        throw new PooledConnectionRefused($message);
      }
      $this->logger->warning($message);
    }
  }

  public function listen(): void {
    $native = $this->connection->getNativeConnection();
    if ($this->listening && $this->listenedOn === $native) {
      return;
    }
    $this->connection->executeStatement('LISTEN ' . $this->channel);
    $this->listening = true;
    $this->listenedOn = $native;
  }

  public function wait(float $seconds): bool {
    $this->payloads = [];
    $seconds = max(0.0, $seconds);

    try {
      $this->listen();
      $pdo = $this->connection->getNativeConnection();
    } catch (\Throwable $e) {
      $this->degrade('LISTEN failed: ' . $e->getMessage());
      $this->sleep($seconds);
      return false;
    }
    if (!$pdo instanceof \PDO || !method_exists($pdo, 'pgsqlGetNotify')) {
      $this->degrade('the native connection is not a pdo_pgsql PDO');
      $this->sleep($seconds);
      return false;
    }

    $first = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, (int) round($seconds * 1000));
    if ($first === false) {
      return false;
    }
    $this->record($first);
    while (($more = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, 0)) !== false) {
      $this->record($more);
    }
    return true;
  }

  /** @return list<string> payloads of the notifications the last wait() consumed */
  public function payloads(): array {
    return $this->payloads;
  }

  /** @param array<string, mixed> $notification */
  private function record(array $notification): void {
    $this->payloads[] = (string) ($notification['payload'] ?? '');
  }

  private function degrade(string $why): void {
    $this->listening = false;
    if (!$this->degraded) {
      $this->degraded = true;
      $this->logger->warning("[ddd relay] LISTEN wakeup unavailable ($why); falling back to polling");
    }
  }

  private function sleep(float $seconds): void {
    if ($seconds > 0) {
      usleep((int) round($seconds * 1_000_000));
    }
  }
}
