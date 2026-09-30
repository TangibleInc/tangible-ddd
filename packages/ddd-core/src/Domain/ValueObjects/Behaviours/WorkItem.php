<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

use DateTimeImmutable;
use TangibleDDD\Domain\Shared\Entity;

/**
 * Persisted work item ledger row for behaviour workflows.
 *
 * Note: despite living under ValueObjects/Behaviours for cohesion, this is an Entity
 * (it has identity and is mutable).
 */
final class WorkItem extends Entity {

  public ?DateTimeImmutable $created_at = null;
  public ?DateTimeImmutable $updated_at = null;

  public function __construct(
    ?int $id,
    public int $workflow_id,
    public int $behaviour_idx,
    public int $phase,
    public string $item_key,
    public WorkItemStatus $status = WorkItemStatus::pending,
    public int $attempts = 0,
    public ?string $last_error = null,
    public array|string|null $payload = null,
    public int $blog_id = 1,
  ) {
    parent::__construct($id);
  }
}


