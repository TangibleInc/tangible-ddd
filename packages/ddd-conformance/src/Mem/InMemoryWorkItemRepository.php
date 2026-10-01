<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Testing\InMemoryTransactional;

/**
 * The mem work-item ledger, enlisted in the boundary. Rows hold a
 * serialized copy, so a reader never gets the live instance (an unsaved
 * change is lost with the worker, as on a SQL host).
 */
final class InMemoryWorkItemRepository implements IWorkItemRepository, InMemoryTransactional {

  /** @var array<int, string> */
  private array $rows = [];

  private int $next = 1;

  public function get_by_id(int $id): WorkItem {
    $blob = $this->rows[$id] ?? throw new \RuntimeException("No work item #$id");
    return unserialize($blob);
  }

  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem {
    foreach (array_keys($this->rows) as $id) {
      $item = $this->get_by_id($id);
      if ([$item->workflow_id, $item->behaviour_idx, $item->phase, $item->item_key] === [$workflow_id, $behaviour_idx, $phase, $item_key]) {
        return $item;
      }
    }
    return null;
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    $items = [];
    foreach (array_keys($this->rows) as $id) {
      $item = $this->get_by_id($id);
      if ([$item->workflow_id, $item->behaviour_idx, $item->phase] === [$workflow_id, $behaviour_idx, $phase]) {
        $items[] = $item;
      }
    }
    return new WorkItemList($items);
  }

  public function save(WorkItem $item): void {
    if ($item->get_id() === null) {
      $item->set_id($this->next++);
    }
    $this->rows[(int) $item->get_id()] = serialize($item);
  }

  public function snapshot(): mixed {
    return [$this->rows, $this->next];
  }

  public function restore(mixed $state): void {
    [$this->rows, $this->next] = $state;
  }
}
