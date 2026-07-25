<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Application\BehaviourWorkflows;

use Tangible\Cred\MegaTrace\Application\Commands\RunCertificationAudit;
use Tangible\Cred\MegaTrace\Domain\Behaviours\NotifyBoards;
use Tangible\Cred\MegaTrace\Domain\Behaviours\ReconcileLedger;
use Tangible\Cred\MegaTrace\Domain\Behaviours\ValidateCompliance;
use Tangible\Cred\MegaTrace\Domain\Events\CertificationAuditRescheduled;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
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

/**
 * The CALM workflow — IssuanceRoutine's counterpart at a REAL time budget.
 *
 * Where IssuanceRoutine sets max_execution_seconds = 0 (one item per pass:
 * maximum passes, maximum causation-chain drama), this routine runs a 2s
 * budget over ~450ms items, so a pass swallows several items, advances the
 * behaviour cursor mid-transaction, and takes a bite of the next behaviour
 * before the budget cuts it — the production shape (cred reporting runs the
 * stock 25s budget). Expect FEW, WIDE, often cross-segment loom brackets.
 *
 * One deterministic pathology: NotifyBoards' 'board_ohio' item fails on its
 * first attempt. Its siblings succeed → PARTIAL failure → the runner FORKS
 * the failed item into a child workflow (root_workflow_id lineage). In the
 * child the lone item fails again (attempts reset on transfer), all-failed
 * → no re-fork → reschedule; the retry pass succeeds. Yields: a fork lane,
 * a failed→retried→done cell, and a ranged bracket — on every story.
 */
final class CertificationAuditRoutine extends WorkflowHandler
{
    public static int $reschedule_interval = 15;

    private const FLAKY_KEY = 'board_ohio';

    public function __construct(
        IBehaviourWorkflowRepository $workflow_repo,
        IWorkItemRepository $item_repo,
        IDDDConfig $infra_config,
        private readonly IIntegrationEventBus $events,
    ) {
        parent::__construct($workflow_repo, $item_repo, $infra_config);
        $this->max_execution_seconds = 2;
    }

    protected function get_workflows(ICommand $command): array
    {
        if (!$command instanceof RunCertificationAudit) {
            return [];
        }
        if ($command->workflow_id !== null) {
            return [$this->workflow_repo->get_by_id($command->workflow_id)];
        }

        return [new BehaviourWorkflow(
            id: null,
            ref_id: ScenarioIds::reference($command->journey_id),
            ref_type: 'mega_trace_certification_audit',
            behaviour_configs: [
                new ValidateCompliance(['scope', 'license', 'hours', 'ethics']),
                new ReconcileLedger(['q1', 'q2', 'q3', 'q4', 'carryover', 'adjustments']),
                new NotifyBoards(['board_home', self::FLAKY_KEY, 'board_compact']),
            ],
            meta: [
                'journey_id' => $command->journey_id,
                'learner_id' => $command->learner_id,
                'portfolio_id' => $command->portfolio_id,
            ],
        )];
    }

    protected function execute_one(
        BaseBehaviourConfig $config,
        WorkItem $item,
        ?BehaviourExecutionResult $previous,
    ): BehaviourExecutionResult {
        SyntheticWorkload::spend(450);

        if ($config instanceof NotifyBoards && $item->item_key === self::FLAKY_KEY && $item->attempts === 0) {
            return new BehaviourExecutionResult(
                type: $config->get_behaviour_type(),
                success: false,
                context: ['message' => 'board endpoint 503 (deterministic first-attempt failure)'],
                status: BehaviourExecutionStatus::failed,
                timestamp: gmdate('c'),
            );
        }

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

    /** Continuation through the fact lane — mirrors IssuanceRoutine::reschedule(). */
    protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
    {
        $this->events->publish(new CertificationAuditRescheduled(
            (string) $workflow->get_meta('journey_id'),
            (int) $workflow->get_meta('learner_id'),
            (string) $workflow->get_meta('portfolio_id'),
            (int) $workflow->get_id(),
            $delay_seconds,
        ));
    }
}
