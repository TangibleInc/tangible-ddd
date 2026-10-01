<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\TransactionFailed;

/**
 * ITransactionBoundary over the host connection (register 3.2, X10, C13).
 *
 * - Wraps the SAME connection the domain repositories and the outbox store
 *   use; never opens, closes or reconfigures it.
 * - Construction over a PdoConnection asserts ERRMODE_EXCEPTION
 *   (PdoConfigurationError otherwise). A host's own IHostConnection must
 *   throw on failure by its contract.
 * - Nesting: NestedPolicy::Reject (default) throws NestedTransactionRejected
 *   before the work runs whenever a transaction is already open, whoever
 *   opened it, and leaves it untouched (a nested START TRANSACTION on MySQL
 *   would implicitly commit the outer one). NestedPolicy::Savepoint runs the
 *   work inside `SAVEPOINT ddd_sp_<depth>` and rolls back to it on failure.
 * - A failed BEGIN or COMMIT throws TransactionFailed (previous = the driver
 *   error); after a failed COMMIT the transaction is rolled back, so nothing
 *   is committed. If the work throws, the transaction is rolled back and the
 *   ORIGINAL exception is rethrown; a rollback failure is logged and never
 *   replaces it.
 */
final class PdoTransactionBoundary implements ITransactionBoundary {

  private readonly LoggerInterface $logger;

  private int $savepoints = 0;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly NestedPolicy $policy = NestedPolicy::Reject,
    ?LoggerInterface $logger = null,
  ) {
    if ($db instanceof PdoConnection) {
      $db->assert_errmode();
    }
    $this->logger = $logger ?? new NullLogger();
  }

  public function run(callable $work): mixed {
    if ($this->db->in_transaction()) {
      if ($this->policy === NestedPolicy::Reject) {
        throw new NestedTransactionRejected(
          'A transaction is already open on this connection; PdoTransactionBoundary (policy Reject) refuses to nest.'
        );
      }
      return $this->inSavepoint($work);
    }

    try {
      $this->db->begin();
    } catch (\Throwable $e) {
      throw new TransactionFailed('BEGIN failed: ' . $e->getMessage(), 0, $e);
    }

    try {
      $result = $work();
    } catch (\Throwable $original) {
      $this->rollBackQuietly($original);
      throw $original;
    }

    try {
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->rollBackQuietly($e);
      throw new TransactionFailed('COMMIT failed: ' . $e->getMessage(), 0, $e);
    }

    return $result;
  }

  public function is_active(): bool {
    return $this->db->in_transaction();
  }

  private function inSavepoint(callable $work): mixed {
    $name = 'ddd_sp_' . (++$this->savepoints);
    try {
      $this->db->execute("SAVEPOINT $name");
      try {
        $result = $work();
      } catch (\Throwable $original) {
        try {
          $this->db->execute("ROLLBACK TO SAVEPOINT $name");
          $this->db->execute("RELEASE SAVEPOINT $name");
        } catch (\Throwable $e) {
          $this->logger->error(sprintf('[ddd tx] rollback to savepoint %s failed after: %s; rollback error: %s', $name, $original->getMessage(), $e->getMessage()));
        }
        throw $original;
      }
      $this->db->execute("RELEASE SAVEPOINT $name");
      return $result;
    } finally {
      $this->savepoints--;
    }
  }

  private function rollBackQuietly(\Throwable $cause): void {
    try {
      if ($this->db->in_transaction()) {
        $this->db->rollback();
      }
    } catch (\Throwable $e) {
      $this->logger->error(sprintf('[ddd tx] rollback failed after: %s; rollback error: %s', $cause->getMessage(), $e->getMessage()));
    }
  }
}
