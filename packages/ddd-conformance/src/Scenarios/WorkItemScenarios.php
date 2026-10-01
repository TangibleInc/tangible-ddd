<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\Workflow\GrantAccess;
use TangibleDDD\Conformance\Fixtures\Workflow\GrantConfig;
use TangibleDDD\Conformance\Fixtures\Workflow\GrantWorkflow;
use TangibleDDD\Conformance\Support\ConformanceConfig;
use TangibleDDD\Conformance\WorkItemHost;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;

/**
 * W4: a work item's command id is deterministic (wave 5, CR-W5C5-3; core
 * CR-W5CC-3). The core WorkflowHandler dispatches the item's first command
 * under DeterministicCommandId::for_item(consumer, workflow id, behaviour
 * index, phase, item key), so a re-run after a crash between the command's
 * commit and the ledger save dispatches the same id and a handler keyed by
 * command id absorbs it. Needs WorkItemHost.
 */
abstract class WorkItemScenarios extends ConformanceTestCase {

  protected const CONSUMER_PREFIX = 'conformance';

  protected function setUp(): void {
    GrantWorkflow::reset();
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    GrantWorkflow::reset();
  }

  #[Group('workflow.item-deterministic-id')]
  #[TestDox('workflow.item-deterministic-id: each work item\'s command runs under for_item(); an item re-run after a crash between its command\'s commit and the ledger save dispatches the same id and is absorbed; another workflow\'s items get other ids')]
  public function test_workflow_item_deterministic_id(): void {
    $host = $this->work_items();
    BaseBehaviourConfig::register_type(GrantConfig::TYPE, GrantConfig::class);
    $rows = $this->host->rows();
    $ran = [];
    GrantWorkflow::$bus = $this->host->command_bus([
      GrantAccess::class => static function (GrantAccess $c) use ($rows, &$ran): void {
        $ran[] = $cid = (string) Correlation::current()->cause?->id;
        $row = GrantAccess::row($cid);
        if (!$rows->has($row)) {
          $rows->insert($row, $c->item_key);
        }
      },
    ]);
    $handler = new GrantWorkflow($host->workflows(), $host->work_items(), new ConformanceConfig(static::CONSUMER_PREFIX));

    // 1. user:2's command commits, then the worker dies before the ledger saves it.
    GrantWorkflow::$crash_after = ['user:2'];
    $workflow = new BehaviourWorkflow(null, 1, 'conformance.grant', [new GrantConfig()]);
    self::assertInstanceOf(\RuntimeException::class, self::thrown(static fn () => $handler->run($workflow)));
    $id = (int) $workflow->get_id();
    self::assertGreaterThan(0, $id, 'the workflow was persisted before its items ran');
    [$first, $second] = array_map(fn (string $key) => $this->item_id($id, $key), GrantWorkflow::ITEMS);
    self::assertSame(
      ['user:1' => WorkItemStatus::done, 'user:2' => WorkItemStatus::pending],
      $this->statuses($id),
      'user:2 is still pending in the ledger',
    );
    self::assertTrue($rows->has(GrantAccess::row($first)), 'user:1\'s command ran under its item id');
    self::assertTrue($rows->has(GrantAccess::row($second)), 'user:2\'s command committed under its item id');
    $granted = $rows->count();

    // 2. The restart re-runs user:2 under the same id; the handler absorbs it.
    $handler->run($host->workflows()->get_by_id($id));

    self::assertSame(['user:1' => WorkItemStatus::done, 'user:2' => WorkItemStatus::done], $this->statuses($id));
    self::assertTrue($host->workflows()->get_by_id($id)->is_complete());
    self::assertSame($granted, $rows->count(), 'the re-run added no grant');
    self::assertSame([$first, $second, $second], $ran, 'the re-run dispatched the same command id');
    self::assertSame([], array_values(array_diff($this->granted_ids(), [$first, $second])), 'the audit trail names no other GrantAccess id');

    // 3. Another workflow: its items have ids of their own.
    $other = new BehaviourWorkflow(null, 1, 'conformance.grant', [new GrantConfig()]);
    $handler->run($other);
    $otherId = (int) $other->get_id();
    self::assertNotSame($id, $otherId);
    $otherFirst = $this->item_id($otherId, 'user:1');
    self::assertNotSame($first, $otherFirst);
    self::assertTrue($rows->has(GrantAccess::row($otherFirst)));
    self::assertSame($granted + 2, $rows->count());
  }

  protected function work_items(): WorkItemHost {
    if (!$this->host instanceof WorkItemHost) {
      $this->skip_for('CR-W5C5-3', 'the host fixture does not implement WorkItemHost yet');
    }
    return $this->host;
  }

  private function item_id(int $workflowId, string $key): string {
    return DeterministicCommandId::for_item(static::CONSUMER_PREFIX, $workflowId, 0, 1, $key);
  }

  /** @return array<string, WorkItemStatus> item key => status in the host ledger */
  private function statuses(int $workflowId): array {
    $out = [];
    foreach ($this->work_items()->work_items()->get_for_step($workflowId, 0, 1) as $item) {
      /** @var WorkItem $item */
      $out[$item->item_key] = $item->status;
    }
    ksort($out);
    return $out;
  }

  /**
   * @return list<string> the command ids the audit trail names for GrantAccess,
   *   in audit order. A host may keep one row per command id (wp), so a
   *   re-run under the same id need not add a row (HC5-2).
   */
  private function granted_ids(): array {
    $ids = [];
    foreach ($this->host->audit_trail() as $entry) {
      /** @var AuditEntry $entry */
      if (str_contains($entry->command_name, 'GrantAccess')) {
        $ids[] = $entry->command_id;
      }
    }
    return $ids;
  }
}
