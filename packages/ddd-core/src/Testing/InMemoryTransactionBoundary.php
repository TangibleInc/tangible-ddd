<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use Psr\Log\LoggerInterface;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\TransactionFailed;

/**
 * In-memory ITransactionBoundary. Enlisted InMemoryTransactional stores are
 * snapshotted at begin/savepoint and restored on rollback or failed commit.
 *
 * Test controls: failNextCommit() (-> TransactionFailed, nothing persists,
 * `cmd.commit-failure`), failNextRollback() (-> logged secondary, original
 * still surfaces).
 */
final class InMemoryTransactionBoundary implements ITransactionBoundary {

  /** @var list<InMemoryTransactional> */
  private array $participants = [];

  private int $depth = 0;

  private int $commits = 0;

  private int $rollbacks = 0;

  private ?string $failCommit = null;

  private ?string $failRollback = null;

  /** @param LoggerInterface|(\Closure(string):void)|null $log PSR-3 logger (closure form deprecated, CR-SP-1) */
  public function __construct(
    private readonly NestedPolicy $policy = NestedPolicy::Reject,
    private readonly LoggerInterface|\Closure|null $log = null,
  ) {}

  public function enlist(InMemoryTransactional $participant): void {
    $this->participants[] = $participant;
  }

  public function run(callable $work): mixed {
    if ($this->depth > 0) {
      if ($this->policy === NestedPolicy::Reject) {
        throw new NestedTransactionRejected('A transaction is already open on this connection (policy Reject).');
      }
      return $this->savepoint($work);
    }

    $snapshot = $this->snapshot();
    $this->depth = 1;

    try {
      $result = $work();
    } catch (\Throwable $original) {
      $this->depth = 0;
      $this->rollbackTo($snapshot, $original);
      throw $original;
    }

    $this->depth = 0;
    if ($this->failCommit !== null) {
      $reason = $this->failCommit;
      $this->failCommit = null;
      $this->restore($snapshot);
      throw new TransactionFailed("COMMIT failed: $reason", 0, new \RuntimeException($reason));
    }
    $this->commits++;

    return $result;
  }

  public function isActive(): bool {
    return $this->depth > 0;
  }

  public function failNextCommit(string $reason): void {
    $this->failCommit = $reason;
  }

  public function failNextRollback(string $reason): void {
    $this->failRollback = $reason;
  }

  public function commits(): int {
    return $this->commits;
  }

  public function rollbacks(): int {
    return $this->rollbacks;
  }

  private function savepoint(callable $work): mixed {
    $snapshot = $this->snapshot();
    $this->depth++;
    try {
      return $work();
    } catch (\Throwable $e) {
      $this->restore($snapshot);
      throw $e;
    } finally {
      $this->depth--;
    }
  }

  /** @param list<mixed> $snapshot */
  private function rollbackTo(array $snapshot, \Throwable $original): void {
    // The mem store always restores; the injected failure only exercises
    // the "logged secondary, original wins" path.
    $this->restore($snapshot);
    $this->rollbacks++;

    if ($this->failRollback !== null) {
      $secondary = new TransactionFailed('ROLLBACK failed: ' . $this->failRollback, 0, new \RuntimeException($this->failRollback));
      $this->failRollback = null;
      Log::write($this->log, sprintf(
        '[ddd tx] %s (while handling %s: %s)',
        $secondary->getMessage(), get_class($original), $original->getMessage()
      ));
    }
  }

  /** @return list<mixed> */
  private function snapshot(): array {
    return array_map(static fn (InMemoryTransactional $p) => $p->snapshotState(), $this->participants);
  }

  /** @param list<mixed> $snapshot */
  private function restore(array $snapshot): void {
    foreach ($this->participants as $i => $p) {
      $p->restoreState($snapshot[$i]);
    }
  }
}
