<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;

final class FakeWorkItemRepository implements IWorkItemRepository {
  /** @var array<int, WorkItem> */
  public array $store = [];
  public int $save_count = 0;
  private int $next_id = 1;

  public function get_by_id(int $id): WorkItem {
    return $this->store[$id] ?? throw new \RuntimeException("WorkItem $id not found");
  }

  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem {
    foreach ($this->store as $item) {
      if (
        $item->workflow_id === $workflow_id
        && $item->behaviour_idx === $behaviour_idx
        && $item->phase === $phase
        && $item->item_key === $item_key
      ) {
        return $item;
      }
    }
    return null;
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    $items = array_values(array_filter(
      $this->store,
      fn(WorkItem $i) => $i->workflow_id === $workflow_id
        && $i->behaviour_idx === $behaviour_idx
        && $i->phase === $phase
    ));
    return new WorkItemList($items);
  }

  public function save(WorkItem $item): void {
    if ($item->get_id() === null) {
      $item->set_id($this->next_id++);
    }
    $this->store[$item->get_id()] = $item;
    $this->save_count++;
  }
}
