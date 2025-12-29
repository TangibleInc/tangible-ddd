<?php

namespace TangibleDDD\Domain\Shared;

/**
 * Base class for domain entities.
 *
 * Entities have identity (an ID) and are mutable.
 */
abstract class Entity {
  protected ?int $id;

  public function __construct(?int $id) {
    $this->id = $id;
  }

  public function get_id(): ?int {
    return $this->id;
  }

  public function set_id(?int $id): void {
    $this->id = $id;
  }

  /**
   * Extract IDs from an array of entities.
   *
   * @param Entity[] $entities
   * @return int[]
   */
  public static function to_ids(array $entities): array {
    return array_map(fn(Entity $e) => $e->get_id(), $entities);
  }
}
