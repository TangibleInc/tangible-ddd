<?php

namespace TangibleDDD\Domain\Shared;

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

// Lived in Infra\Shared until 0.6.6; see ddd-src/Infra/Shared/StringList.php.
class_alias(StringList::class, 'TangibleDDD\Infra\Shared\StringList');


