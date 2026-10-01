<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

/**
 * An in-memory store that takes part in InMemoryTransactionBoundary: its
 * state is snapshotted at BEGIN (and at each savepoint) and restored on
 * rollback, which is how mem doubles model "same connection" atomicity.
 */
interface InMemoryTransactional {
  public function snapshot(): mixed;
  public function restore(mixed $state): void;
}
