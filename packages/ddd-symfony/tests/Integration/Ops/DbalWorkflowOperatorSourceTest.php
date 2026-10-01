<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Ops;

use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Ops\DbalWorkflowOperatorSource;
use TangibleDDD\Symfony\Persistence\DbalWorkItemRepository;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/**
 * W5 (TXP process-kernel demands): layer `workflow` of the operator view on
 * Postgres 16: work items past their retries, failed workflows, and ignition
 * or start markers that never got a workflow and are older than
 * stale_start_seconds (a start that died).
 */
final class DbalWorkflowOperatorSourceTest extends PostgresTestCase {

  private function workflowRow(bool $failed, int $idx = 0, int $phase = 1): int {
    return (int) $this->db->fetchOne(
      "INSERT INTO ddd_behaviour_workflows (ref_id, ref_type, behaviour_configs, behaviour_results, current_idx, current_phase, is_failed, updated_at)
       VALUES (1, 'digest', '[]', '[]', ?, ?, ?, now() - interval '1 minute') RETURNING id",
      [$idx, $phase, $failed ? 'true' : 'false']
    );
  }

  public function test_lists_failed_items_failed_workflows_and_stale_start_markers(): void {
    $items = new DbalWorkItemRepository($this->db);
    $wf = $this->workflowRow(false);
    $items->save(new WorkItem(null, $wf, 0, 1, 'user:1', WorkItemStatus::failed, 4, 'smtp down'));
    $items->save(new WorkItem(null, $wf, 0, 1, 'user:2', WorkItemStatus::done, 1));
    $items->save(new WorkItem(null, $wf, 0, 1, 'user:3', WorkItemStatus::pending, 0));
    $failed = $this->workflowRow(true, 2, 1);

    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T10:00:00Z'));
    $ledger = new DbalWorkflowIgnitionLedger($this->db, '', $clock);
    $ledger->claim('stale-marker', 'digest', 'evt-1');
    $ledger->claim('young-marker', 'digest', 'evt-2');
    $this->db->executeStatement("UPDATE ddd_workflow_ignitions SET created_at = now() - interval '2 hours' WHERE dedup_key = 'stale-marker'");
    $this->db->executeStatement("UPDATE ddd_workflow_ignitions SET created_at = now() WHERE dedup_key = 'young-marker'");
    $ledger->claim('started', 'digest', 'evt-3');
    $ledger->attach('started', $wf);
    $this->db->executeStatement("UPDATE ddd_workflow_ignitions SET created_at = now() - interval '2 hours' WHERE dedup_key = 'started'");

    $view = (new DbalWorkflowOperatorSource($this->db, 'txp', '', 900, 3))->items(null, 10);

    self::assertSame(
      ["item:$wf:0:1:user:1", "workflow:$failed", 'ignition:stale-marker'],
      array_map(static fn ($i) => $i->key, $view)
    );
    foreach ($view as $item) {
      self::assertSame(Layer::Workflow, $item->layer);
      self::assertSame('txp', $item->consumer);
      self::assertNotNull($item->first_seen);
    }
    self::assertSame(4, $view[0]->attempts);
    self::assertSame(3, $view[0]->budget);
    self::assertSame('smtp down', $view[0]->last_error);
    self::assertStringContainsString('failed at step 2', (string) $view[1]->last_error);
    self::assertStringContainsString('never got a workflow', (string) $view[2]->last_error);
  }

  public function test_honours_layer_and_limit(): void {
    $this->workflowRow(true);
    $this->workflowRow(true);
    $source = new DbalWorkflowOperatorSource($this->db, 'txp');

    self::assertCount(1, $source->items(Layer::Workflow, 1));
    self::assertSame([], $source->items(Layer::Delivery, 10));
    self::assertSame([], $source->items(null, 0));
  }
}
