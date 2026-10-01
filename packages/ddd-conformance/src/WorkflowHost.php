<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;

/**
 * Optional seam for `workflow.fact-ignition-once` (D10; wave 4, CR-W4C4-4;
 * register 3.11 D10, ruling #78). A separate interface, so HostFixture is
 * unchanged; a fixture without it has the scenario skipped with the
 * request id.
 *
 * All three bind to the fixture's per-test schema and connection:
 *
 * - workflowIgnitionLedger(): the host's IWorkflowIgnitionLedger (sf
 *   DbalWorkflowIgnitionLedger on `ddd_workflow_ignitions`);
 * - workflowRepository(): the host's behaviour-workflow store;
 * - workflowIgniter(): the core WorkflowIgniter the host wires (over that
 *   ledger, HostFixture::boundary() and HostFixture::clock()).
 *
 * The scenario registers its workflow with workflowIgniter()->register()
 * on HostFixture::subscriptions() and delivers through HostFixture::deliver().
 */
interface WorkflowHost {

  public function workflowIgnitionLedger(): IWorkflowIgnitionLedger;

  public function workflowRepository(): IBehaviourWorkflowRepository;

  public function workflowIgniter(): WorkflowIgniter;
}
