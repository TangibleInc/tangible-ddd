<?php

namespace TangibleDDD\Domain\Repositories;

use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;

interface IWorkItemRepository {

  public function get_by_id(int $id): WorkItem;

  public function find_by_unique(
    int $workflow_id,
    int $behaviour_idx,
    int $phase,
    string $item_key
  ): ?WorkItem;

  /**
   * @return WorkItemList
   */
  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList;

  /**
   * Insert/update the work item and hydrate its ID.
   */
  public function save(WorkItem $item): void;
}


