<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

/** One workflow ignition ledger entry (D10). */
final class WorkflowIgnition {

  public function __construct(
    public readonly string $dedupKey,
    /** the workflow kind (IStartsFromFact::workflow_kind()) */
    public readonly string $kind,
    /** null until attach() (or when the winner's save never attached) */
    public readonly ?int $workflowId,
    /** the igniting fact's event id, when the ignition came from a fact */
    public readonly ?string $eventId,
    public readonly \DateTimeImmutable $createdAt,
  ) {}
}
