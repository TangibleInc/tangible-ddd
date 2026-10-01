<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Domain\BehaviourWorkflow;

/**
 * A behaviour workflow ended in the failed state (a behaviour failed and the
 * workflow could not fork/recover).
 *
 * Carries correlation (captured from the active context — the workflow runs
 * inside its driving command) so a reaction rejoins the trace. Carries NO
 * causation: per doctrine a workflow is a coordinator, not a causer (its own
 * commands are event-driven), so there is no parent edge to express here. This
 * is an observability signal, not a reaction trigger.
 */
final class WorkflowFailed extends InfrastructureEvent {

  public function __construct(
    BehaviourWorkflow $workflow,
    ?string $correlation_id = null,
  ) {
    parent::__construct($workflow, $correlation_id, null, null);
  }

  public static function action(): string {
    return 'workflow_failed';
  }

  public function workflow(): BehaviourWorkflow {
    return $this->subject;
  }
}
