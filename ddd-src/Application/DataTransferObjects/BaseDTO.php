<?php

namespace TangibleDDD\Application\DataTransferObjects;

/**
 * Base class for Data Transfer Objects.
 *
 * Provides default implementations for serialization methods.
 * Extend this class and define public properties for your DTO fields.
 */
class BaseDTO implements IDataTransferObject {

  public function to_std(): \stdClass {
    return json_decode(json_encode($this));
  }

  public function to_array(): array {
    return (array) $this->to_std();
  }
}
