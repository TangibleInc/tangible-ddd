<?php

namespace TangibleDDD\Domain\Shared;

use TangibleDDD\Application\DataTransferObjects\Collections\IDTOCollection;
use TangibleDDD\Application\DataTransferObjects\IDataTransferObject;

/**
 * Interface for domain objects that can be constructed from DTOs.
 *
 * Implement this interface on value objects and entities that need
 * to be hydrated from application-layer DTOs.
 */
interface IDTOConstructible {

  /**
   * Create an instance from a DTO.
   *
   * @param IDataTransferObject $dto
   * @return static
   */
  public static function from_dto(IDataTransferObject $dto): static;

  /**
   * Create multiple instances from a DTO collection.
   *
   * @param IDTOCollection $list
   * @return static[]
   */
  public static function from_dto_list(IDTOCollection $list): array;
}
