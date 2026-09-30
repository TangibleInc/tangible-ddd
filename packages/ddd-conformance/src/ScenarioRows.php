<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * The scenario's "domain row": a tiny table (`id` primary key, `value`) that
 * the host creates in the per-test schema ON THE SAME CONNECTION as the
 * boundary and the outbox, so a rollback removes it with the outbox row.
 */
interface ScenarioRows {

  /** Insert inside whatever transaction is open; throws on a duplicate id. */
  public function insert(string $id, string $value): void;

  public function has(string $id): bool;

  public function count(): int;
}
