<?php

namespace TangibleDDD\Application\DataTransferObjects\Collections;

use TangibleDDD\Application\DataTransferObjects\IDataTransferObject;

/**
 * Base class for DTO collections.
 *
 * Provides a simple array-based implementation.
 * Extend this class to create typed collections.
 */
class BaseDTOCollection implements IDTOCollection {

  /** @var IDataTransferObject[] */
  protected array $items = [];

  /**
   * @param IDataTransferObject[] $items
   */
  public function __construct(array $items = []) {
    $this->items = $items;
  }

  public function items(): array {
    return $this->items;
  }

  public function count(): int {
    return count($this->items);
  }

  public function isEmpty(): bool {
    return empty($this->items);
  }

  /**
   * Create a collection from an array of data.
   * Override in subclasses to provide typed instantiation.
   *
   * @param array $data Array of DTO data
   * @return static
   */
  public static function fromArray(array $data): static {
    return new static($data);
  }
}
