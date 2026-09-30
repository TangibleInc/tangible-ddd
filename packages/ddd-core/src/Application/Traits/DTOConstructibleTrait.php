<?php

namespace TangibleDDD\Application\Traits;

use TangibleDDD\Application\DataTransferObjects\Collections\IDTOCollection;

/**
 * Trait providing default implementation for IDTOConstructible::from_dto_list().
 *
 * Classes using this trait must implement from_dto() method.
 */
trait DTOConstructibleTrait {

  /**
   * Create multiple instances from a DTO collection.
   *
   * @param IDTOCollection $list
   * @param string|null $idx_property Optional property to use as array key
   * @return static[]
   */
  public static function from_dto_list(IDTOCollection $list, ?string $idx_property = null): array {
    $items = $list->items();
    $buf = [];

    foreach ($items as $idx => $dto) {
      $obj = static::from_dto($dto);

      if (null !== $idx_property) {
        if (property_exists($obj, $idx_property)) {
          $idx = $obj->$idx_property;
        } elseif (method_exists($obj, $idx_property)) {
          $idx = $obj->$idx_property();
        }
      }

      $buf[$idx] = $obj;
    }

    return $buf;
  }
}
