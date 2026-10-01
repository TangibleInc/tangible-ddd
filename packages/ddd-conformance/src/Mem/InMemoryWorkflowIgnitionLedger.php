<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnition;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Testing\InMemoryTransactional;

/**
 * The mem D10 ignition ledger: one entry per dedup key, enlisted in the
 * InMemoryTransactionBoundary so a rolled-back ignition releases its key.
 * Same behaviour as ddd-core's test double MemIgnitionLedger (W4P-R2 asks
 * for it in ddd-core Testing; until then it lives here).
 */
final class InMemoryWorkflowIgnitionLedger implements IWorkflowIgnitionLedger, InMemoryTransactional {

  /** @var array<string, WorkflowIgnition> */
  private array $rows = [];

  public function __construct(private readonly IClock $clock) {}

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool {
    if (isset($this->rows[$dedupKey])) {
      return false;
    }
    $this->rows[$dedupKey] = new WorkflowIgnition($dedupKey, $kind, null, $eventId, $this->clock->now());
    return true;
  }

  public function attach(string $dedupKey, int $workflowId): void {
    $row = $this->rows[$dedupKey] ?? null;
    if ($row !== null) {
      $this->rows[$dedupKey] = new WorkflowIgnition($row->key, $row->kind, $workflowId, $row->event_id, $row->created_at);
    }
  }

  public function find(string $dedupKey): ?WorkflowIgnition {
    return $this->rows[$dedupKey] ?? null;
  }

  public function release(string $dedupKey): void {
    unset($this->rows[$dedupKey]);
  }

  public function snapshot(): mixed {
    return $this->rows;
  }

  public function restore(mixed $state): void {
    $this->rows = $state;
  }
}
