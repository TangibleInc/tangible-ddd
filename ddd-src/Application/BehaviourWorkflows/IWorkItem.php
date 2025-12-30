<?php

namespace TangibleDDD\Application\BehaviourWorkflows;

interface IWorkItem {

  /**
   * Stable idempotency key for this work item within the workflow step.
   * Example: "grant|user:123|course:456"
   */
  public function key(): string;

  /**
   * Optional payload to persist in the work item ledger.
   * Keep it JSON-serializable (array|string|null preferred).
   */
  public function payload(): array|string|null;
}


