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
 * - $work that caught a statement error (Postgres aborted transaction,
 *   25P02): detected by a `SELECT 1` probe before COMMIT, rolled back,
 *   TransactionFailed. Without it Postgres' COMMIT silently rolls back.
 * - Savepoint mode never rolls back below the level it found: the outer
 *   transaction belongs to the caller.
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

    // The caller's level (0 unless Savepoint mode nests). Nothing at or below
    // it is ours: every rollback below stops there.
    $outer = $this->connection->getTransactionNestingLevel();

    try {
      $this->connection->beginTransaction();
    } catch (\Throwable $e) {
      throw new TransactionFailed('BEGIN failed: ' . $e->getMessage(), 0, $e);
    }
    // DBAL 4 names the savepoint after the level it opens (DOCTRINE_<n>).
    $savepoint = 'DOCTRINE_' . ($outer + 1);

    try {
      $result = $work();
      if ($this->beforeCommit !== null) {
        ($this->beforeCommit)();
      }
    } catch (\Throwable $original) {
      $this->rollBackTo($outer, $original);
      throw $original;
    }

    // Aborted-transaction probe. When $work swallowed a statement error,
    // Postgres has marked the transaction aborted (25P02) and a COMMIT would
    // answer with the ROLLBACK tag and no error, so commit() would not throw
    // and the command would report success with its rows gone. Any statement
    // in an aborted transaction raises, so one cheap round trip turns the
    // silent loss into TransactionFailed. On engines without aborted
    // transactions (MySQL) it is a no-op SELECT.
    try {
      $this->connection->executeQuery('SELECT 1');
    } catch (\Throwable $e) {
      $this->rollBackTo($outer, $e);
      throw new TransactionFailed(
        'Transaction aborted by an earlier statement error the work caught; nothing was committed: ' . $e->getMessage(),
        0,
        $e
      );
    }

    try {
      $this->connection->commit();
    } catch (\Throwable $e) {
      $this->recoverAfterFailedCommit($outer, $savepoint, $e);
      throw new TransactionFailed('COMMIT failed: ' . $e->getMessage(), 0, $e);
    }

    return $result;
  }

  public function isActive(): bool {
    return $this->connection->isTransactionActive();
  }

  /**
   * Roll back what this run opened (the transaction, or its savepoint), and
   * never the caller's outer transaction.
   */
  private function rollBackTo(int $outer, \Throwable $cause): void {
    try {
      while ($this->connection->getTransactionNestingLevel() > $outer) {
        $this->connection->rollBack();
      }
    } catch (\Throwable $rollback) {
      $this->logger->error(sprintf(
        '[ddd tx] ROLLBACK failed: %s (while handling %s: %s)',
        $rollback->getMessage(), get_class($cause), $cause->getMessage()
      ), ['exception' => $rollback]);
    }
  }

  /**
   * DBAL 4 decrements its nesting level in the finally of commit(), so after
   * a failed COMMIT / RELEASE the connection already reports the caller's
   * level.
   *
   * - Outermost (level 0): Postgres ended the transaction; make sure the
   *   native PDO handle agrees so the next BEGIN on this long-lived
   *   connection works.
   * - Savepoint mode: the savepoint still exists server-side; roll back to
   *   it by name, which also clears an aborted state, and leave the outer
   *   transaction to its owner. rollBack() is never called here: at the
   *   caller's level it would end the caller's transaction.
   */
  private function recoverAfterFailedCommit(int $outer, string $savepoint, \Throwable $e): void {
    try {
      if ($this->connection->getTransactionNestingLevel() > $outer) {
        $this->rollBackTo($outer, $e);
        return;
      }
      if ($outer > 0) {
        $this->connection->rollbackSavepoint($savepoint);
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
