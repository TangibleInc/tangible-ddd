<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Defaults\Pdo\IHostConnection;

/**
 * The fixture's IHostConnection: the real PdoConnection plus the driver-level
 * seams the scenarios need, so every adapter above it is the production one.
 *
 * - failNextCommit(): the next COMMIT on this connection throws before it
 *   reaches the server, as a driver error at COMMIT would; the transaction
 *   is still open, so the boundary must roll it back (cmd.commit-failure).
 * - GET_LOCK steering through the shared LockProbe (see there).
 */
final class ScenarioConnection implements IHostConnection {

  private ?string $failCommit = null;

  public function __construct(
    private readonly IHostConnection $inner,
    private readonly LockProbe $locks,
    private readonly bool $primary,
  ) {}

  public function inner(): IHostConnection {
    return $this->inner;
  }

  public function failNextCommit(string $reason): void {
    $this->failCommit = $reason;
  }

  public function execute(string $sql, array $params = []): int {
    return $this->inner->execute($sql, $params);
  }

  public function fetchAll(string $sql, array $params = []): array {
    return $this->inner->fetchAll($sql, $params);
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    if (!str_contains($sql, 'GET_LOCK(')) {
      return $this->inner->fetchOne($sql, $params);
    }

    if ($this->primary && ($before = $this->locks->takeBefore()) !== null) {
      $before();
    }
    if (($reason = $this->locks->takeFailure()) !== null) {
      // The backend's NULL answer (a server error, a killed session): the
      // lock was not taken. $reason is the scenario's label for it.
      return ['acquired' => null, 'conformance_fault' => $reason];
    }
    $row = $this->inner->fetchOne($sql, $params);
    if ((string) ($row['acquired'] ?? '') === '1') {
      $this->locks->acquisitions++;
    }
    return $row;
  }

  public function lastInsertId(): string {
    return $this->inner->lastInsertId();
  }

  public function begin(): void {
    $this->inner->begin();
  }

  public function commit(): void {
    if ($this->failCommit !== null) {
      $reason = $this->failCommit;
      $this->failCommit = null;
      throw new \PDOException("SQLSTATE[HY000]: General error: COMMIT failed ($reason)");
    }
    $this->inner->commit();
  }

  public function rollBack(): void {
    $this->inner->rollBack();
  }

  public function inTransaction(): bool {
    return $this->inner->inTransaction();
  }

  public function isDuplicateKey(\Throwable $e): bool {
    return $this->inner->isDuplicateKey($e);
  }
}
