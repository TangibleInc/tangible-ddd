<?php

namespace TangibleDDD\Domain\Shared;

/**
 * Interface for objects with JSON serialization.
 */
interface IJsonSerializable {

  /**
   * Reconstruct the object from JSON data.
   *
   * @param \stdClass|array|string $data JSON string, decoded object, or array
   */
  public static function from_json(\stdClass|array|string $data): static;

  /**
   * Serialize the object to JSON.
   *
   * @param bool $as_string If true, return JSON string; otherwise return array/object
   * @return string|\stdClass|array
   */
  public function to_json(bool $as_string = true): string|\stdClass|array;
}
