<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

/**
 * The workflow ignition ledger (D10, register 3.11, ruling #78): one entry
 * per caller-supplied dedup key; a unique constraint on the key is the gate
 * (scenario workflow.fact-ignition-once). Hosts: sf
 * `ddd_workflow_ignitions` (DbalWorkflowIgnitionLedger), pdo and wp when a
 * consumer needs D10 (O8, O9).
 *
 * - claim(): insert the key; true = this caller won and starts the
 *   workflow, false = the key already ignited one (a duplicate delivery, a
 *   second cron tick in the same minute). MUST run on the connection and in
 *   the transaction that saves the workflow, so a rolled-back start
 *   releases the key. A concurrent claimer of the same key waits for the
 *   first one's transaction and then loses (INSERT ... ON CONFLICT DO
 *   NOTHING / INSERT IGNORE semantics; never SQLSTATE class 23000 alone).
 * - attach(): record which workflow the winning claim started (same
 *   transaction).
 * - find(): the entry, null when the key never ignited.
 * - release(): the explicit repair path (an operator re-runs an ignition);
 *   the next claim of the key wins again.
 *
 * Error behaviour: storage failures throw; claim() never returns false for
 * a failure.
 */
interface IWorkflowIgnitionLedger {

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool;

  public function attach(string $dedupKey, int $workflowId): void;

  public function find(string $dedupKey): ?WorkflowIgnition;

  public function release(string $dedupKey): void;
}
