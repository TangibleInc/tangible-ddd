<?php

namespace TangibleDDD\Application\DataTransferObjects;

/**
 * Interface for Data Transfer Objects.
 *
 * DTOs are simple objects that carry data between processes.
 * They should be immutable and contain no business logic.
 */
interface IDataTransferObject {

  /**
   * Convert the DTO to a stdClass object.
   */
  public function to_std(): \stdClass;

  /**
   * Convert the DTO to an associative array.
   */
  public function to_array(): array;
}
