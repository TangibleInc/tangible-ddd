<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Symfony\Persistence\TableNames;
use TangibleDDD\Symfony\Persistence\Time;

/**
 * Layer `workflow` of the operator view (W5; core Layer::Workflow, D10), in
 * this order, oldest first within each:
 *
 * - work items past their retries (`ddd_behaviour_workflow_items`, status
 *   `failed`): key `item:{workflow}:{idx}:{phase}:{item_key}`, attempts
 *   against $budget (WorkflowHandler::$max_retries unless configured);
 * - failed workflows (`ddd_behaviour_workflows.is_failed`): key
 *   `workflow:{id}`, no budget;
 * - ignition entries or start markers with no workflow that are older than
 *   $stale_start_seconds (`ddd_workflow_ignitions`; W3: the start died, and
 *   the next fact with the key restarts it): key `ignition:{dedup_key}`.
 *
 * No repair labels: a failed item or workflow has no library repair command
 * yet (a host re-runs it with its own command), and a stale marker is
 * reclaimed by the igniter itself. Storage errors propagate.
 */
final class DbalWorkflowOperatorSource implements IOperatorItemSource {

  private readonly string $items;
  private readonly string $workflows;
  private readonly string $ignitions;

  public function __construct(
    private readonly Connection $connection,
    private readonly string $consumer,
    string $tablePrefix = '',
    private readonly int $stale_start_seconds = 900,
    private readonly ?int $budget = null,
    private readonly ?IClock $clock = null,
  ) {
    $tables = TableNames::of($tablePrefix);
    $this->items = $tables->table('ddd_behaviour_workflow_items');
    $this->workflows = $tables->table('ddd_behaviour_workflows');
    $this->ignitions = $tables->table('ddd_workflow_ignitions');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Workflow)) {
      return [];
    }
    $out = $this->failed_items($limit);
    if (count($out) < $limit) {
      $out = [...$out, ...$this->failed_workflows($limit - count($out))];
    }
    if (count($out) < $limit) {
      $out = [...$out, ...$this->stale_starts($limit - count($out))];
    }
    return $out;
  }

  /** @return list<OperatorItem> */
  private function failed_items(int $limit): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT workflow_id, behaviour_idx, phase, item_key, attempts, last_error, updated_at FROM {$this->items}
        WHERE status = 'failed' ORDER BY updated_at, id LIMIT ?",
      [$limit], [ParameterType::INTEGER]
    );
    return array_map(fn (array $r) => new OperatorItem(
      Layer::Workflow, $this->consumer,
      sprintf('item:%d:%d:%d:%s', $r['workflow_id'], $r['behaviour_idx'], $r['phase'], $r['item_key']),
      (int) $r['attempts'], $this->budget ?? WorkflowHandler::$max_retries,
      $r['last_error'] === null ? null : (string) $r['last_error'],
      Time::from_db((string) $r['updated_at']),
    ), $rows);
  }

  /** @return list<OperatorItem> */
  private function failed_workflows(int $limit): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT id, ref_type, ref_id, current_idx, current_phase, updated_at FROM {$this->workflows}
        WHERE is_failed ORDER BY updated_at, id LIMIT ?",
      [$limit], [ParameterType::INTEGER]
    );
    return array_map(fn (array $r) => new OperatorItem(
      Layer::Workflow, $this->consumer, 'workflow:' . $r['id'], 0, null,
      sprintf('workflow failed at step %d phase %d (%s #%d)', $r['current_idx'], $r['current_phase'], $r['ref_type'], $r['ref_id']),
      Time::from_db((string) $r['updated_at']),
    ), $rows);
  }

  /** @return list<OperatorItem> */
  private function stale_starts(int $limit): array {
    // The igniter judges age by its clock (the ledger writes created_at with it), so this does too.
    $cutoff = ($this->clock ?? new SystemClock())->now()->modify("-{$this->stale_start_seconds} seconds");
    $rows = $this->connection->fetchAllAssociative(
      "SELECT dedup_key, kind, event_id, created_at FROM {$this->ignitions}
        WHERE workflow_id IS NULL AND created_at <= ?
        ORDER BY created_at, dedup_key LIMIT ?",
      [Time::to_db($cutoff), $limit], [ParameterType::STRING, ParameterType::INTEGER]
    );
    return array_map(fn (array $r) => new OperatorItem(
      Layer::Workflow, $this->consumer, 'ignition:' . $r['dedup_key'], 0, null,
      sprintf('%s ignition or start marker never got a workflow (older than %d s; event %s): the start died, the next fact with the key restarts it',
        $r['kind'], $this->stale_start_seconds, $r['event_id'] ?? '-'),
      Time::from_db((string) $r['created_at']),
    ), $rows);
  }
}
