<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/**
 * The sf fixture's main DBAL connection (DBAL `wrapperClass`), with one
 * test-only ability: asAnotherConnection($other, $fn) runs $fn with every
 * statement and transaction call of THIS connection object sent to $other,
 * a second physical session on the same database.
 *
 * That is the RelayRace seam (CR-W3CP-2): the competitor runs between the
 * relay's submission and accept() while the relay holds its transaction
 * open on this connection, and acts through the very store objects the
 * scenario captured (HostFixture::outbox()). Its statements and commits
 * happen on $other, so they stay committed whatever the relay's own
 * transaction does afterwards, as a second relay process's would.
 *
 * Outside asAnotherConnection() it is a plain DBAL Connection.
 */
class RaceableConnection extends Connection {

  private ?Connection $borrowed = null;

  /**
   * @template T
   * @param callable(): T $fn
   * @return T
   */
  public function asAnotherConnection(Connection $other, callable $fn): mixed {
    $previous = $this->borrowed;
    $this->borrowed = $other;
    try {
      return $fn();
    } finally {
      $this->borrowed = $previous;
    }
  }

  public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result {
    return $this->borrowed !== null
      ? $this->borrowed->executeQuery($sql, $params, $types, $qcp)
      : parent::executeQuery($sql, $params, $types, $qcp);
  }

  public function executeStatement(string $sql, array $params = [], array $types = []): int|string {
    return $this->borrowed !== null
      ? $this->borrowed->executeStatement($sql, $params, $types)
      : parent::executeStatement($sql, $params, $types);
  }

  public function beginTransaction(): void {
    $this->borrowed !== null ? $this->borrowed->beginTransaction() : parent::beginTransaction();
  }

  public function commit(): void {
    $this->borrowed !== null ? $this->borrowed->commit() : parent::commit();
  }

  public function rollBack(): void {
    $this->borrowed !== null ? $this->borrowed->rollBack() : parent::rollBack();
  }

  public function isTransactionActive(): bool {
    return $this->borrowed !== null ? $this->borrowed->isTransactionActive() : parent::isTransactionActive();
  }

  public function getTransactionNestingLevel(): int {
    return $this->borrowed !== null ? $this->borrowed->getTransactionNestingLevel() : parent::getTransactionNestingLevel();
  }

  public function setRollbackOnly(): void {
    $this->borrowed !== null ? $this->borrowed->setRollbackOnly() : parent::setRollbackOnly();
  }

  public function isRollbackOnly(): bool {
    return $this->borrowed !== null ? $this->borrowed->isRollbackOnly() : parent::isRollbackOnly();
  }
}
