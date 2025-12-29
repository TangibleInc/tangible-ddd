<?php

namespace TangibleDDD\Domain\Shared;

/**
 * Interface for value objects with array serialization.
 */
interface IValueObject {

  /**
   * Serialize the value object to an array.
   */
  public function to_array(): array;

  /**
   * Reconstruct the value object from an array.
   */
  public static function from_array(array $data): static;
}
