<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * IStartsFromFact defaults for WorkflowHandler subclasses (D10): kind = the
 * handler class; key = uuid5(event_id, kind) (one workflow per fact; '' for
 * an id-less fact, which then ignites without dedup); save through the
 * handler's workflow repository; start = handle_workflow(); load (the
 * restart of a failed start, ILoadsIgnitedWorkflow) = get_by_id().
 *
 * @phpstan-require-extends WorkflowHandler
 */
trait StartsFromFacts {

  public function workflow_kind(): string {
    return static::class;
  }

  public function ignition_key(IIntegrationEvent $fact, string $eventId): string {
    return $eventId === '' ? '' : WorkflowIgnitionKey::forFact($eventId, $this->workflow_kind());
  }

  public function save_ignited(BehaviourWorkflow $workflow): void {
    $this->workflow_repo->save($workflow);
  }

  /** ILoadsIgnitedWorkflow: the restart path of a workflow whose start failed. */
  public function load_ignited(int $workflowId): ?BehaviourWorkflow {
    return $this->workflow_repo->get_by_id($workflowId);
  }

  public function start_ignited(BehaviourWorkflow $workflow): void {
    $this->started_at = time();
    $this->handle_workflow($workflow);
  }
}
