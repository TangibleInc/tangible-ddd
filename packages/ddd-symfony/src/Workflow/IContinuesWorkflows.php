<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Workflow;

use TangibleDDD\Domain\BehaviourWorkflow;

/**
 * W1: a WorkflowHandler whose reschedule() is a durable wakeup intent
 * (ReschedulesThroughWakeups implements both sides). The bundle
 * autoconfigures it (DddTags::CONTINUES_WORKFLOW) and the workflow wake
 * target calls continue_workflow() when the intent comes due.
 */
interface IContinuesWorkflows {

  /** Run $workflow on from where it stands (WorkflowHandler::handle_workflow()). */
  public function continue_workflow(BehaviourWorkflow $workflow): void;
}
