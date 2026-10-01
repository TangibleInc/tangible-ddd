<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Workflows;

use TangibleDDD\Application\BehaviourWorkflows\IStartsFromFact;
use TangibleDDD\Application\BehaviourWorkflows\StartsFromFacts;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\CronTicked;
use TangibleDDD\Symfony\Tests\Support\Fixtures\StopBehaviourConfig;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

/**
 * D10: a behaviour workflow ignited by a cron tick, once per (entry, minute)
 * (TXP's "(workflow, minute)" key). Entry "skip" is declined. The start
 * records a row in app_listener_runs, then runs the workflow (one behaviour
 * with no work items) through WorkflowHandler, which saves it complete via
 * the DBAL repository.
 */
#[StartsOn(CronTicked::class)]
final class NightlyReportWorkflow extends WorkflowHandler implements IStartsFromFact {
  use StartsFromFacts { start_ignited as private run_ignited; }

  public function __construct(
    IBehaviourWorkflowRepository $workflows,
    IWorkItemRepository $items,
    private readonly WidgetRepository $runs,
  ) {
    parent::__construct($workflows, $items);
  }

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
    if (!$fact instanceof CronTicked || $fact->entry === 'skip') {
      return null;
    }
    StopBehaviourConfig::register();
    return new BehaviourWorkflow(null, 1, 'cron:' . $fact->entry, [new StopBehaviourConfig($fact->entry)]);
  }

  public function ignition_key(IIntegrationEvent $fact, string $eventId): string {
    assert($fact instanceof CronTicked);
    return WorkflowIgnitionKey::per_minute($this->workflow_kind() . ':' . $fact->entry, new \DateTimeImmutable($fact->due_at));
  }

  public function start_ignited(BehaviourWorkflow $workflow): void {
    $this->runs->recordListenerRun('workflow-start', (string) $workflow->get_id(), null);
    $this->run_ignited($workflow);
  }

  protected function get_workflows(ICommand $command): array { return []; }

  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
    throw new \LogicException('the nightly report has no behaviours in this test');
  }

  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    return new WorkItemList([]);
  }

  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}
