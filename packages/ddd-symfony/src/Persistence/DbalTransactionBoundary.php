<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\TransactionFailed;

/**
 * ITransactionBoundary over the DBAL connection the domain repositories and
 * the outbox share (register 3.2, X10, E section 5).
 *
 * - Default policy Reject: when the connection already has a transaction
 *   open, run() throws NestedTransactionRejected before $work and leaves the
 *   outer transaction alone. Savepoint (opt-in per instance) runs $work in a
 *   DBAL savepoint instead, for hosts whose tests wrap each test in a
 *   transaction.
 * - $work throwing: rollback, then the ORIGINAL exception is rethrown; a
 *   rollback failure is logged, never substituted.
 * - BEGIN / COMMIT failure: TransactionFailed with the driver error as
 *   previous; nothing is committed and the connection is left outside any
 *   transaction.
 * - $beforeCommit (e.g. `EntityManager::flush`) runs inside the transaction
 *   just before COMMIT; if it throws, the transaction rolls back and its
 *   exception surfaces.
 *
 * It never opens, closes or reconfigures the connection. Messenger's
 * `doctrine_transaction` middleware must not wrap the bus that dispatches DDD
 * commands (the bundle refuses to compile it; E section 5): it would turn
 * this transaction into a savepoint of a message-wide one.
 */
final class DbalTransactionBoundary implements ITransactionBoundary {

  private readonly LoggerInterface $logger;

  /** @var (\Closure(): void)|null */
  private readonly ?\Closure $beforeCommit;

  public function __construct(
    private readonly Connection $connection,
    private readonly NestedPolicy $policy = NestedPolicy::Reject,
    ?callable $beforeCommit = null,
    ?LoggerInterface $logger = null,
  ) {
    $this->beforeCommit = $beforeCommit === null ? null : \Closure::fromCallable($beforeCommit);
    $this->logger = $logger ?? new NullLogger();
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function run(callable $work): mixed {
    if ($this->connection->isTransactionActive() && $this->policy === NestedPolicy::Reject) {
      throw new NestedTransactionRejected(
        'A transaction is already open on this DBAL connection (policy Reject). '
        . 'Is Messenger\'s doctrine_transaction middleware wrapping DDD dispatch?'
      );
    }

    try {
      $this->connection->beginTransaction();
    } catch (\Throwable $e) {
      throw new TransactionFailed('BEGIN failed: ' . $e->getMessage(), 0, $e);
    }

    try {
      $result = $work();
      if ($this->beforeCommit !== null) {
        ($this->beforeCommit)();
      }
    } catch (\Throwable $original) {
      $this->rollBackAfter($original);
      throw $original;
    }

    try {
      $this->connection->commit();
    } catch (\Throwable $e) {
      $this->recoverAfterFailedCommit($e);
      throw new TransactionFailed('COMMIT failed: ' . $e->getMessage(), 0, $e);
    }

    return $result;
  }

  public function isActive(): bool {
    return $this->connection->isTransactionActive();
  }

  private function rollBackAfter(\Throwable $original): void {
    try {
      if ($this->connection->isTransactionActive()) {
        $this->connection->rollBack();
      }
    } catch (\Throwable $rollback) {
      $this->logger->error(sprintf(
        '[ddd tx] ROLLBACK failed: %s (while handling %s: %s)',
        $rollback->getMessage(), get_class($original), $original->getMessage()
      ), ['exception' => $rollback]);
    }
  }

  /**
   * Postgres ends the transaction when COMMIT fails; DBAL already dropped its
   * nesting level. Make sure the native PDO handle agrees, so the next
   * BEGIN on this long-lived connection works.
   */
  private function recoverAfterFailedCommit(\Throwable $e): void {
    try {
      if ($this->connection->isTransactionActive()) {
        $this->connection->rollBack();
        return;
      }
      $native = $this->connection->getNativeConnection();
      if ($native instanceof \PDO && $native->inTransaction()) {
        $native->rollBack();
      }
    } catch (\Throwable $cleanup) {
      $this->logger->warning('[ddd tx] cleanup after failed COMMIT raised: ' . $cleanup->getMessage(), [
        'exception' => $cleanup,
        'commit_error' => $e->getMessage(),
      ]);
    }
  }
}
