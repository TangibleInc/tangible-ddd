<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

/**
 * Shared by every ScenarioConnection of one fixture: what the process-lock
 * scenarios need to see and steer at the MySQL GET_LOCK call that
 * MySqlNamedLock makes (register 3.7). Working at the driver keeps the
 * production lock stack (ReentrantProcessLock over MySqlNamedLock) intact.
 *
 * - acquisitions: GET_LOCK calls that answered 1, on any worker connection
 *   (re-entrant acquisitions never reach the backend, so they are not seen);
 * - failNext(): the next GET_LOCK on any worker connection answers NULL
 *   without reaching the server (MySqlNamedLock must fail closed);
 * - beforeNext(): runs once right before the next GET_LOCK on the PRIMARY
 *   connection (worker 1), with no transaction open there.
 */
final class LockProbe {

  public int $acquisitions = 0;

  /** @var list<string> */
  private array $failures = [];

  /** @var list<callable(): void> */
  private array $before = [];

  public function failNext(string $reason): void {
    $this->failures[] = $reason;
  }

  public function beforeNext(callable $fn): void {
    $this->before[] = $fn;
  }

  /** @internal */
  public function takeFailure(): ?string {
    return array_shift($this->failures);
  }

  /** @internal */
  public function takeBefore(): ?callable {
    return array_shift($this->before);
  }
}
