<?php

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;

abstract class PersistsAggregatesRepository implements IPersistsAggregates {

  public function __construct(
    protected readonly EventsUnitOfWork $events
  ) {}

  /**
   * Get the expected aggregate class for this repository
   *
   * @return class-string<Aggregate>
   */
  abstract protected function get_aggregate_class(): string;

  /**
   * Perform the actual persistence logic
   *
   * @param Aggregate $aggregate The aggregate to persist
   * @return void
   */
  abstract protected function persist(Aggregate $aggregate): void;

  /**
   * Save an aggregate with automatic event collection
   *
   * @param Aggregate $aggregate The aggregate to save
   * @return void
   * @throws TypeMismatchException If aggregate type doesn't match expected type
   */
  public function save(Aggregate $aggregate): void {
    $expected = $this->get_aggregate_class();
    if (!$aggregate instanceof $expected) {
      throw new TypeMismatchException(
        sprintf(
          'Repository %s expected %s, got %s',
          static::class,
          $expected,
          get_class($aggregate)
        )
      );
    }

    $this->persist($aggregate);
    $this->events->collect_from($aggregate);
  }
}
