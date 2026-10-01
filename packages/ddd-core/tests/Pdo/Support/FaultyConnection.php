<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Support;

use TangibleDDD\Defaults\Pdo\IHostConnection;

/**
 * Decorates a real IHostConnection and injects one failure on demand:
 * begin, commit or rollback throw, or a statement matching a pattern throws.
 * The real operation still runs where that matters (a failing COMMIT is
 * simulated before the real commit, so the transaction stays open and the
 * boundary must roll it back).
 */
final class FaultyConnection implements IHostConnection {

  public ?string $failOn = null;          // 'begin' | 'commit' | 'rollback'
  public ?string $failStatement = null;   // regex on SQL
  public ?\Throwable $failWith = null;

  /** @var list<string> */
  public array $statements = [];

  public function __construct(private readonly IHostConnection $inner) {}

  public function inner(): IHostConnection {
    return $this->inner;
  }

  public function execute(string $sql, array $params = []): int {
    $this->maybeFailStatement($sql);
    return $this->inner->execute($sql, $params);
  }

  public function fetch_all(string $sql, array $params = []): array {
    $this->maybeFailStatement($sql);
    return $this->inner->fetch_all($sql, $params);
  }

  public function fetch_one(string $sql, array $params = []): ?array {
    $this->maybeFailStatement($sql);
    return $this->inner->fetch_one($sql, $params);
  }

  public function last_insert_id(): string {
    return $this->inner->last_insert_id();
  }

  public function begin(): void {
    $this->maybeFail('begin');
    $this->inner->begin();
  }

  public function commit(): void {
    $this->maybeFail('commit');
    $this->inner->commit();
  }

  public function rollback(): void {
    if ($this->failOn === 'rollback') {
      $this->inner->rollback();
    }
    $this->maybeFail('rollback');
    $this->inner->rollback();
  }

  public function in_transaction(): bool {
    return $this->inner->in_transaction();
  }

  public function is_duplicate_key(\Throwable $e): bool {
    return $this->inner->is_duplicate_key($e);
  }

  private function maybeFail(string $op): void {
    if ($this->failOn === $op) {
      $this->failOn = null;
      throw $this->failWith ?? new \RuntimeException("injected $op failure");
    }
  }

  private function maybeFailStatement(string $sql): void {
    $this->statements[] = $sql;
    if ($this->failStatement !== null && preg_match($this->failStatement, $sql)) {
      $this->failStatement = null;
      throw $this->failWith ?? new \RuntimeException('injected statement failure: ' . $sql);
    }
  }
}
