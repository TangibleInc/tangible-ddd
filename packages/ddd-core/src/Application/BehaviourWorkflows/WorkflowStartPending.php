<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

/**
 * Thrown by the ignition subscriber (WorkflowIgniter::register) when the
 * fact's key already ignited a workflow whose start has not completed: the
 * start is in flight, or the worker running it died. The delivery ledger
 * records a failed attempt and retries; once the start marker is older than
 * the igniter's stale_start_seconds, a retry reclaims it and starts the
 * workflow. A start that never completes dead-letters the fact, which is
 * where an operator sees it.
 */
final class WorkflowStartPending extends \RuntimeException {

  public function __construct(public readonly string $dedupKey, public readonly int $workflowId) {
    parent::__construct(sprintf(
      'workflow #%d (ignition key %s) ignited but its start has not completed; retry later',
      $workflowId, $dedupKey
    ));
  }
}
