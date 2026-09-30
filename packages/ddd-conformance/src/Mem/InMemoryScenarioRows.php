<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Testing\InMemoryTransactional;

/** The mem "domain table": enlisted in the InMemoryTransactionBoundary like the outbox. */
final class InMemoryScenarioRows implements ScenarioRows, InMemoryTransactional {

  /** @var array<string, string> */
  private array $rows = [];

  public function insert(string $id, string $value): void {
    if (isset($this->rows[$id])) {
      throw new \RuntimeException("Duplicate scenario row $id");
    }
    $this->rows[$id] = $value;
  }

  public function has(string $id): bool {
    return isset($this->rows[$id]);
  }

  public function count(): int {
    return count($this->rows);
  }

  public function snapshotState(): mixed {
    return $this->rows;
  }

  public function restoreState(mixed $state): void {
    $this->rows = $state;
  }
}
