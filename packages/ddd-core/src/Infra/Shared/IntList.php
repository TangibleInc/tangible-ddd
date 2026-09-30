<?php

namespace TangibleDDD\Infra\Shared;

final class IntList extends TypedList {

  public function __construct(array $list = []) {
    $list = filter_var_array($list, FILTER_VALIDATE_INT);
    parent::__construct($list);
  }

  public function offsetGet($index): int {
    return $this->protected_get($index);
  }

  public function current(): int {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return 'int';
  }
}


