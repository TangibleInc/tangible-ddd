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
 * WorkflowIgniter also keeps one start marker per ignited key in the same
 * ledger (key WorkflowIgnitionKey::startMarker($dedupKey), a 36-character
 * uuid5, same kind), claimed right before the start, released when the
 * start throws, and attach()ed to the workflow id when the start returns
 * (fix round 2: an unattached marker is a start in flight or a dead
 * starter; the igniter reclaims one older than its stale_start_seconds).
 * Stores need nothing extra for it: attach() and createdAt work on any key.
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
