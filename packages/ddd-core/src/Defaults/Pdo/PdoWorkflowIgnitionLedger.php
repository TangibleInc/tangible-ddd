<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnition;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IWorkflowIgnitionLedger (D10, O8; wave 4) on
 * `{prefix}ddd_workflow_ignitions`, whose primary key on dedup_key is the
 * gate (workflow.fact-ignition-once).
 *
 * - claim(): a plain INSERT on the host connection, so it commits or rolls
 *   back with the transaction that saves the workflow. A duplicate key
 *   (MySQL 1062 only, IHostConnection::is_duplicate_key) answers false; it
 *   rolls back only the statement, so the caller's transaction stays usable.
 *   A concurrent claimer of the same key waits on InnoDB's lock for the
 *   first one's uncommitted row, then loses (or wins if that rolled back).
 *   Not INSERT IGNORE: that would turn other errors (a key longer than the
 *   column, a missing table) into a silent false. Any other error throws
 *   \RuntimeException.
 * - attach(): sets workflow_id. find(): the entry or null. release(): deletes
 *   the row (the explicit repair path; the next claim wins again).
 *
 * WorkflowIgniter's start markers are ordinary rows here. created_at comes
 * from IClock (UTC), which the igniter's stale-claim and stale-start rules
 * compare against its own clock.
 */
final class PdoWorkflowIgnitionLedger implements IWorkflowIgnitionLedger {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_workflow_ignitions');
    $this->clock = $clock ?? new SystemClock();
  }

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool {
    try {
      $this->db->execute(
        "INSERT INTO `{$this->table}` (dedup_key, kind, event_id, created_at) VALUES (?, ?, ?, ?)",
        [$dedupKey, $kind, $eventId, Utc::to_db($this->clock->now())]
      );
      return true;
    } catch (\Throwable $e) {
      if ($this->db->is_duplicate_key($e)) {
        return false;
      }
      throw new \RuntimeException("Workflow ignition claim of '$dedupKey' failed: " . $e->getMessage(), 0, $e);
    }
  }

  public function attach(string $dedupKey, int $workflowId): void {
    $this->guard('attach', $dedupKey, fn () => $this->db->execute(
      "UPDATE `{$this->table}` SET workflow_id = ? WHERE dedup_key = ?",
      [$workflowId, $dedupKey]
    ));
  }

  public function find(string $dedupKey): ?WorkflowIgnition {
    $row = $this->guard('find', $dedupKey, fn () => $this->db->fetch_one("SELECT * FROM `{$this->table}` WHERE dedup_key = ?", [$dedupKey]));
    if ($row === null) {
      return null;
    }
    return new WorkflowIgnition(
      (string) $row['dedup_key'],
      (string) $row['kind'],
      $row['workflow_id'] === null ? null : (int) $row['workflow_id'],
      $row['event_id'] === null ? null : (string) $row['event_id'],
      Utc::from_db((string) $row['created_at']),
    );
  }

  public function release(string $dedupKey): void {
    $this->guard('release', $dedupKey, fn () => $this->db->execute("DELETE FROM `{$this->table}` WHERE dedup_key = ?", [$dedupKey]));
  }

  private function guard(string $operation, string $key, callable $statement): mixed {
    try {
      return $statement();
    } catch (\RuntimeException $e) {
      throw $e;
    } catch (\Throwable $e) {
      throw new \RuntimeException("Workflow ignition $operation('$key') failed: " . $e->getMessage(), 0, $e);
    }
  }
}
