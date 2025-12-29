<?php

namespace TangibleDDD\Application\TypedLists;

use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Infra\Shared\TypedList;

/**
 * TypedList for JsonLifecycleValue objects.
 *
 * Useful when you want a strongly-typed list of JsonLifecycleValue subclasses
 * (preserves unknown JSON properties and supports round-trip serialization).
 */
final class JsonLifecycleValueList extends TypedList {

  public function offsetGet($index): JsonLifecycleValue {
    return $this->protected_get($index);
  }

  public function current(): JsonLifecycleValue {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return JsonLifecycleValue::class;
  }
}


