<?php

namespace TangibleDDD\Domain\Shared;

/**
 * Abstract base class for value objects.
 *
 * Provides default array serialization using reflection on public properties.
 * Subclasses can override for custom serialization logic.
 */
abstract class ValueObject implements IValueObject {

  public function to_array(): array {
    return get_object_vars($this);
  }

  public static function from_array(array $data): static {
    return new static(...$data);
  }
}
