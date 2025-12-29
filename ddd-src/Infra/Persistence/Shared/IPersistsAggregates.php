<?php

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Domain\Shared\Aggregate;

interface IPersistsAggregates {

  /**
   * Save an aggregate to persistence
   *
   * @param Aggregate $aggregate The aggregate to save
   * @return void
   */
  public function save(Aggregate $aggregate): void;

  /**
   * Retrieve an aggregate by ID
   *
   * @param int $id The aggregate ID
   * @return Aggregate|null The aggregate if found, null otherwise
   */
  #[\ReturnTypeWillChange]
  public function get_by_id(int $id): ?Aggregate;
}
