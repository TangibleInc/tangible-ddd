<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

use TangibleDDD\Infra\Shared\TypedList;

/**
 * @extends TypedList<WorkItem>
 */
final class WorkItemList extends TypedList {

  public function offsetGet($index): WorkItem {
    return $this->protected_get($index);
  }

  public function current(): WorkItem {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return WorkItem::class;
  }

  // ─────────────────────────────────────────────────────────────
  // Status Filters (all return new instances, original unchanged)
  // ─────────────────────────────────────────────────────────────

  public function pending(): self {
    return $this->filter(fn(WorkItem $i) => $i->status === WorkItemStatus::pending, clone: true);
  }

  public function waiting(): self {
    return $this->filter(fn(WorkItem $i) => $i->status === WorkItemStatus::waiting, clone: true);
  }

  public function failed(): self {
    return $this->filter(fn(WorkItem $i) => $i->status === WorkItemStatus::failed, clone: true);
  }

  public function done(): self {
    return $this->filter(fn(WorkItem $i) => $i->status === WorkItemStatus::done, clone: true);
  }

  public function has_pending(): bool { return !$this->pending()->empty(); }
  public function has_waiting(): bool { return !$this->waiting()->empty(); }
  public function has_failed(): bool { return !$this->failed()->empty(); }

  // ─────────────────────────────────────────────────────────────
  // Slicing
  // ─────────────────────────────────────────────────────────────

  public function take(int $n): self {
    return new self(array_slice($this->_list, 0, max(1, $n)));
  }

  // ─────────────────────────────────────────────────────────────
  // Aggregate Status (for decision-making)
  // ─────────────────────────────────────────────────────────────

  /**
   * Determine the aggregate status of this list.
   *
   * Priority: pending > waiting > failed > done
   */
  public function aggregate_status(): WorkItemStatus {
    if ($this->has_pending()) return WorkItemStatus::pending;
    if ($this->has_waiting()) return WorkItemStatus::waiting;
    if ($this->has_failed()) return WorkItemStatus::failed;
    return WorkItemStatus::done;
  }

  /**
   * Extract item keys from all items.
   *
   * For forking, these keys become the batch for the child workflow.
   *
   * @return array<string>
   */
  public function item_keys(): array {
    return array_map(fn(WorkItem $i) => $i->item_key, $this->_list);
  }
}


