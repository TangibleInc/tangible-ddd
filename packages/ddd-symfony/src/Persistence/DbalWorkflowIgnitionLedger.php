<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Ids\NameBasedUuid;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The workflow ignition ledger (D10, ruling #78; scenario
 * workflow.fact-ignition-once): one row per caller-supplied dedup key in
 * `ddd_workflow_ignitions`, whose primary key is the gate.
 *
 * - claim(): `INSERT ... ON CONFLICT (dedup_key) DO NOTHING`; true means
 *   this caller won and starts the workflow, false that the key already
 *   ignited one. Run it in the transaction that creates the workflow, so a
 *   rolled-back start releases the key.
 * - attach(): records which workflow the winning claim started.
 * - find(): the ledger entry, null when the key never ignited.
 * - release(): the explicit repair path (an operator re-runs an ignition).
 *
 * Key choice is the caller's: keyForFact() = uuid5(event_id, kind) for a
 * fact-ignited workflow (the same fact delivered twice ignites once); a cron
 * tick uses e.g. "CronEntryDue:<entry>:<minute>". sf-local in wave 3; the
 * core D10 contract wires it in wave 4.
 */
final class DbalWorkflowIgnitionLedger {

  private readonly string $table;

  public function __construct(private readonly Connection $connection, string $tablePrefix = '') {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_workflow_ignitions');
  }

  public static function keyForFact(string $eventId, string $kind): string {
    return NameBasedUuid::v5($eventId, $kind);
  }

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool {
    return $this->connection->executeStatement(
      "INSERT INTO {$this->table} (dedup_key, kind, event_id) VALUES (?, ?, ?) ON CONFLICT (dedup_key) DO NOTHING",
      [$dedupKey, $kind, $eventId]
    ) === 1;
  }

  public function attach(string $dedupKey, int $workflowId): void {
    $this->connection->executeStatement(
      "UPDATE {$this->table} SET workflow_id = ? WHERE dedup_key = ?",
      [$workflowId, $dedupKey],
      [ParameterType::INTEGER, ParameterType::STRING]
    );
  }

  /** @return ?array{kind: string, workflow_id: ?int, event_id: ?string, created_at: \DateTimeImmutable} */
  public function find(string $dedupKey): ?array {
    $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE dedup_key = ?", [$dedupKey]);
    if ($row === false) {
      return null;
    }
    return [
      'kind' => (string) $row['kind'],
      'workflow_id' => $row['workflow_id'] === null ? null : (int) $row['workflow_id'],
      'event_id' => $row['event_id'] === null ? null : (string) $row['event_id'],
      'created_at' => Time::fromDb((string) $row['created_at']),
    ];
  }

  public function release(string $dedupKey): void {
    $this->connection->executeStatement("DELETE FROM {$this->table} WHERE dedup_key = ?", [$dedupKey]);
  }
}
