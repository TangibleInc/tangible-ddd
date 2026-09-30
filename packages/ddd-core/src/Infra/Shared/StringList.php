<?php

namespace TangibleDDD\Infra\Shared;

final class StringList extends TypedList {
  public function offsetGet($index): string {
    return $this->protected_get($index);
  }

  public function current(): string {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return 'string';
  }
}


