<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Workflows;

use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;
use TangibleDDD\Symfony\Tests\Support\Fixtures\StopBehaviourConfig;
use TangibleDDD\Symfony\Workflow\IContinuesWorkflows;
use TangibleDDD\Symfony\Workflow\ReschedulesThroughWakeups;

/**
 * W1: a workflow that runs out of its resource budget after every item
 * (max_execution_seconds 0), so each run does one item and reschedules the
 * rest through the wakeup scheduler (ReschedulesThroughWakeups). Items are
 * recorded in app_listener_runs as `digest-item`.
 */
final class ChunkedDigestWorkflow extends WorkflowHandler implements IContinuesWorkflows {
  use ReschedulesThroughWakeups;

  public static int $reschedule_interval = 0;

  protected int $max_execution_seconds = 0;

  public function __construct(
    IBehaviourWorkflowRepository $workflows,
    IWorkItemRepository $items,
    private readonly WidgetRepository $runs,
  ) {
    StopBehaviourConfig::register(); // before any continuation loads a workflow (W2)
    parent::__construct($workflows, $items);
  }

  public function start(string $name): BehaviourWorkflow {
    $workflow = new BehaviourWorkflow(null, 1, 'digest:' . $name, [new StopBehaviourConfig($name)]);
    $this->continue_workflow($workflow);
    return $workflow;
  }

  protected function get_workflows(ICommand $command): array { return []; }

  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    return new WorkItemList(array_map(static fn (string $k) => new WorkItem(null, 0, 0, 1, $k), ['a', 'b', 'c']));
  }

  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
    $this->runs->recordListenerRun('digest-item', $item->item_key, (string) $item->workflow_id);
    return new BehaviourExecutionResult($config->get_behaviour_type(), true, [], BehaviourExecutionStatus::completed);
  }
}
