<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Workflow;

use League\Tactician\CommandBus;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;

/**
 * W4 (workflow.item-deterministic-id): a core WorkflowHandler with one
 * GrantConfig behaviour and two work items, `user:1` and `user:2`. Each
 * item sends GrantAccess(item key) through the host bus ($bus).
 *
 * $crash_after lists item keys whose next run dies after their command
 * committed and before the ledger saved the item (the worker crashed in
 * between); each key crashes once.
 */
final class GrantWorkflow extends WorkflowHandler {

  public const ITEMS = ['user:1', 'user:2'];

  public static ?CommandBus $bus = null;

  /** @var list<string> */
  public static array $crash_after = [];

  public static function reset(): void {
    self::$bus = null;
    self::$crash_after = [];
  }

  public static function bus(): CommandBus {
    return self::$bus ?? throw new \LogicException('GrantWorkflow::$bus is not set (HostFixture::command_bus())');
  }

  /** Run (or continue) $workflow in this worker. */
  public function run(BehaviourWorkflow $workflow): void {
    $this->handle_workflow($workflow);
  }

  protected function get_workflows(ICommand $command): array {
    return [];
  }

  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
    (new GrantAccess($item->item_key))->send();

    $crash = array_search($item->item_key, self::$crash_after, true);
    if ($crash !== false) {
      unset(self::$crash_after[$crash]);
      throw new \RuntimeException("worker died after {$item->item_key}'s command committed");
    }

    return new BehaviourExecutionResult(
      type: $config->get_behaviour_type(),
      success: true,
      context: ['message' => 'granted'],
      status: BehaviourExecutionStatus::completed,
      timestamp: gmdate('c'),
      phase: 1,
    );
  }

  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    return new WorkItemList(array_map(static fn (string $key) => new WorkItem(null, 0, 0, 1, $key), self::ITEMS));
  }

  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}
