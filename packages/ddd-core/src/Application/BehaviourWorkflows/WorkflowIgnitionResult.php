<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

/** What WorkflowIgniter::ignite() did with one fact (D10). */
final class WorkflowIgnitionResult {

  public function __construct(
    public readonly WorkflowIgnitionOutcome $outcome,
    /** null when declined, or when the ignition could not dedup (empty key) */
    public readonly ?string $dedupKey = null,
    /** the ignited workflow; for AlreadyIgnited, the winner's (when attached) */
    public readonly ?int $workflowId = null,
    /** start_ignited() threw after the ignition committed */
    public readonly ?\Throwable $startError = null,
    /**
     * AlreadyIgnited only: the winner's start marker is claimed but the
     * start has not completed (in flight, or its worker died; reclaimed once
     * older than the igniter's stale_start_seconds). The registered
     * subscriber throws WorkflowStartPending for it, so the delivery retries.
     */
    public readonly bool $startPending = false,
  ) {}
}
