<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * The unit-of-work transaction (register 3.2).
 *
 * run() begins, runs $work, commits, and returns $work's value unchanged (D11).
 *
 * Error behaviour:
 * - If $work throws, run() rolls back and rethrows the ORIGINAL exception. A
 *   rollback failure is logged as a secondary (with the driver error as its
 *   previous) and never replaces the original.
 * - A failed BEGIN or COMMIT throws TransactionFailed (previous = the driver
 *   error), so the command reports failure; nothing is committed.
 * - When a transaction is already open: policy Reject (default) throws
 *   NestedTransactionRejected before $work runs and leaves the outer
 *   transaction untouched; policy Savepoint runs $work inside a savepoint.
 *
 * Connection rules: the boundary wraps the SAME connection the domain
 * repositories and the outbox store use. It never opens, closes or
 * reconfigures a connection. pdo requires PDO::ERRMODE_EXCEPTION and refuses
 * construction otherwise; wp checks every WordPress query result; sf wraps the DBAL
 * connection and flushes the ORM before commit when configured.
 *
 * Lifetime: one instance per connection, shared by every command.
 */
interface ITransactionBoundary {

  /**
   * @template T
   * @param callable():T $work
   * @return T
   * @throws NestedTransactionRejected|TransactionFailed|\Throwable
   */
  public function run(callable $work): mixed;

  public function is_active(): bool;
}
