<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Application\BehaviourWorkflows;

use Tangible\Cred\MegaTrace\Application\Commands\RunIssuanceRoutine;
use Tangible\Cred\MegaTrace\Domain\Behaviours\PrepareCredentialArtifacts;
use Tangible\Cred\MegaTrace\Domain\Behaviours\ReviewIssuanceEvidence;
use Tangible\Cred\MegaTrace\Domain\Events\IssuanceRoutineItemCompleted;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\MegaTrace\Command\SyntheticWorkload;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

final class IssuanceRoutine extends WorkflowHandler
{
    public static int $reschedule_interval = 18;

    public function __construct(
        IBehaviourWorkflowRepository $workflow_repo,
        IWorkItemRepository $item_repo,
        IDDDConfig $infra_config,
        private readonly IIntegrationEventBus $events,
    ) {
        parent::__construct($workflow_repo, $item_repo, $infra_config);
        $this->max_execution_seconds = 0;
    }

    protected function get_workflows(ICommand $command): array
    {
        if (!$command instanceof RunIssuanceRoutine) {
            return [];
        }
        if ($command->workflow_id !== null) {
            return [$this->workflow_repo->get_by_id($command->workflow_id)];
        }

        return [new BehaviourWorkflow(
            id: null,
            ref_id: ScenarioIds::reference($command->journey_id),
            ref_type: 'mega_trace_certification',
            behaviour_configs: [
                new ReviewIssuanceEvidence(['identity', 'assessment', 'completion']),
                new PrepareCredentialArtifacts(['certificate', 'transcript', 'badge']),
            ],
            meta: [
                'journey_id' => $command->journey_id,
                'learner_id' => $command->learner_id,
                'portfolio_id' => $command->portfolio_id,
                'correlation_id' => Correlation::peek()?->correlation_id,
            ],
        )];
    }

    protected function execute_one(
        BaseBehaviourConfig $config,
        WorkItem $item,
        ?BehaviourExecutionResult $previous,
    ): BehaviourExecutionResult {
        SyntheticWorkload::spend(SyntheticWorkload::routine_item_ms($item->item_key));

        $this->events->publish(new IssuanceRoutineItemCompleted(
            (string) $this->current_workflow->get_meta('journey_id'),
            (string) $this->current_workflow->get_meta('portfolio_id'),
            $config->get_behaviour_type(),
            $item->item_key,
        ));

        return new BehaviourExecutionResult(
            type: $config->get_behaviour_type(),
            success: true,
            context: ['message' => 'completed'],
            status: BehaviourExecutionStatus::completed,
            timestamp: gmdate('c'),
        );
    }

    protected function generate_work_items(
        BehaviourWorkflow $workflow,
        BaseBehaviourConfig $config,
    ): WorkItemList {
        $batch = property_exists($config, 'batch') ? $config->batch : [];
        return new WorkItemList(array_map(
            static fn ($item): WorkItem => new WorkItem(
                id: null,
                workflow_id: $workflow->get_id() ?? 0,
                behaviour_idx: $workflow->get_current_idx(),
                phase: $workflow->get_current_phase(),
                item_key: (string) $item,
            ),
            $batch,
        ));
    }

    protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
    {
        as_schedule_single_action(
            time() + $delay_seconds,
            $this->infra_config->hook('mega_trace_workflow_continue'),
            [
                'workflow_id' => $workflow->get_id(),
                'journey_id' => (string) $workflow->get_meta('journey_id'),
                'learner_id' => (int) $workflow->get_meta('learner_id'),
                'portfolio_id' => (string) $workflow->get_meta('portfolio_id'),
                'correlation_id' => (string) $workflow->get_meta('correlation_id'),
            ],
            $this->infra_config->as_group('behaviours'),
        );
    }
}
