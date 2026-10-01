<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use TangibleDDD\Domain\BehaviourWorkflow;

/**
 * Optional companion of IStartsFromFact (D10, wave 4 fix round 1): load a
 * workflow that ignited but whose start never completed, so WorkflowIgniter
 * can start it on the next delivery of an igniting fact (the delivery
 * ledger's retry of the failed start, or a later fact with the same key).
 *
 * The StartsFromFacts trait provides load_ignited() through the handler's
 * workflow repository; WorkflowIgniter uses that method whether or not the
 * class also declares this interface. null = the workflow is gone.
 */
interface ILoadsIgnitedWorkflow {

  public function load_ignited(int $workflowId): ?BehaviourWorkflow;
}
