<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnition;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The core D10 workflow ignition ledger (IWorkflowIgnitionLedger, ruling
 * #78; scenario workflow.fact-ignition-once) on `ddd_workflow_ignitions`:
 * one row per caller-supplied dedup key, whose primary key is the gate.
 *
 * - claim(): `INSERT ... ON CONFLICT (dedup_key) DO NOTHING`; true means
 *   this caller won and starts the workflow, false that the key already
 *   ignited one. A concurrent claimer of the same key waits for the first
 *   one's transaction and then loses. Writes join the caller's transaction
 *   on the domain connection (WorkflowIgniter runs claim, save and attach in
 *   one ITransactionBoundary::run), so a rolled-back start releases the key.
 * - attach(): records which workflow the winning claim started.
 * - find(): the entry as a core WorkflowIgnition, null when the key never
 *   ignited.
 * - release(): the explicit repair path (an operator re-runs an ignition).
 *
 * WorkflowIgniter also keeps its start markers here
 * (WorkflowIgnitionKey::start_marker(), a 36-character uuid5): ordinary rows,
 * nothing extra is needed. Key choice is the caller's: fact_key() (=
 * WorkflowIgnitionKey::for_fact) for "once per fact", a cron tick uses
 * WorkflowIgnitionKey::per_minute(). Storage errors propagate as DBAL
 * exceptions; claim() never returns false for a failure.
 */
final class DbalWorkflowIgnitionLedger implements IWorkflowIgnitionLedger {

  private readonly string $table;

  /**
   * @param ?IClock $clock writes created_at (WorkflowIgniter compares it with its own clock for
   *   stale claims and start markers); null = the database's now()
   */
  public function __construct(
    private readonly Connection $connection,
    string $tablePrefix = '',
    private readonly ?IClock $clock = null,
  ) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_workflow_ignitions');
  }

  /** uuid5(event_id, kind): the same key as core WorkflowIgnitionKey::for_fact(). */
  public static function fact_key(string $eventId, string $kind): string {
    return WorkflowIgnitionKey::for_fact($eventId, $kind);
  }

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool {
    if ($this->clock === null) {
      return $this->connection->executeStatement(
        "INSERT INTO {$this->table} (dedup_key, kind, event_id) VALUES (?, ?, ?) ON CONFLICT (dedup_key) DO NOTHING",
        [$dedupKey, $kind, $eventId]
      ) === 1;
    }
    return $this->connection->executeStatement(
      "INSERT INTO {$this->table} (dedup_key, kind, event_id, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (dedup_key) DO NOTHING",
      [$dedupKey, $kind, $eventId, Time::to_db($this->clock->now())]
    ) === 1;
  }

  public function attach(string $dedupKey, int $workflowId): void {
    $this->connection->executeStatement(
      "UPDATE {$this->table} SET workflow_id = ? WHERE dedup_key = ?",
      [$workflowId, $dedupKey],
      [ParameterType::INTEGER, ParameterType::STRING]
    );
  }

  public function find(string $dedupKey): ?WorkflowIgnition {
    $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE dedup_key = ?", [$dedupKey]);
    if ($row === false) {
      return null;
    }
    return new WorkflowIgnition(
      (string) $row['dedup_key'],
      (string) $row['kind'],
      $row['workflow_id'] === null ? null : (int) $row['workflow_id'],
      $row['event_id'] === null ? null : (string) $row['event_id'],
      Time::from_db((string) $row['created_at']),
    );
  }

  public function release(string $dedupKey): void {
    $this->connection->executeStatement("DELETE FROM {$this->table} WHERE dedup_key = ?", [$dedupKey]);
  }
}
