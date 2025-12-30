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
}


