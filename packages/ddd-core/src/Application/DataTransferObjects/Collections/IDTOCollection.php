<?php

namespace TangibleDDD\Application\DataTransferObjects\Collections;

use TangibleDDD\Application\DataTransferObjects\IDataTransferObject;

/**
 * Interface for collections of DTOs.
 */
interface IDTOCollection {

  /**
   * Get all items in the collection.
   *
   * @return IDataTransferObject[]
   */
  public function items(): array;
}
