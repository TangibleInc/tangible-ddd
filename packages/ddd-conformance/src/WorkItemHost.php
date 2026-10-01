<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;

/**
 * Optional seam for `workflow.item-deterministic-id` (W4; wave 5,
 * CR-W5C5-3; core CR-W5CC-3). A separate interface, so HostFixture and
 * WorkflowHost are unchanged and a host without an ignition ledger (wp)
 * can provide it; a fixture without it has the scenario skipped with the
 * request id.
 *
 * Both bind to the fixture's per-test schema and connection:
 *
 * - workflows(): the host's behaviour-workflow store (the same as
 *   WorkflowHost::workflows() when the fixture implements both); it stores
 *   behaviour configs as the host does and reads them back through
 *   BaseBehaviourConfig's type registry;
 * - work_items(): the host's work-item ledger (wp WorkItemRepository, pdo
 *   PdoWorkItemRepository, sf's DBAL store).
 *
 * The scenario runs a core WorkflowHandler over them and dispatches each
 * item's command through HostFixture::command_bus().
 */
interface WorkItemHost {

  public function workflows(): IBehaviourWorkflowRepository;

  public function work_items(): IWorkItemRepository;
}
