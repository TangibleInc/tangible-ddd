<?php

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;

/**
 * The 0.6 repository base: final persist-then-harvest save().
 *
 * save() accepts any IRecordsDomainEvents (TXP demand L2); the class check
 * against get_aggregate_class() still guards it. A 0.6 subclass keeps
 * `persist(Aggregate $aggregate)` and works unchanged, because its
 * get_aggregate_class() names an Aggregate. A subclass persisting an
 * identity-agnostic root widens persist() to `persist(IRecordsDomainEvents
 * $aggregate)` (a legal contravariant override). Such a subclass still owes
 * IPersistsAggregates::get_by_id(int): ?Aggregate; when that does not fit,
 * extend AggregateRootRepository instead.
 */
abstract class PersistsAggregatesRepository implements IPersistsAggregates {

  public function __construct(
    protected readonly EventsUnitOfWork $events
  ) {}

  /**
   * Get the expected aggregate class for this repository
   *
   * @return class-string<IRecordsDomainEvents>
   */
  abstract protected function get_aggregate_class(): string;

  /**
   * Perform the actual persistence logic. Subclasses may widen the
   * parameter to IRecordsDomainEvents (see the class docblock).
   *
   * @param Aggregate $aggregate The aggregate to persist
   * @return void
   */
  abstract protected function persist(Aggregate $aggregate): void;

  /**
   * Save an aggregate with automatic event collection.
   *
   * FINAL: persist-then-collect is the seal's ground truth — a subclass
   * overriding save() to skip collect_from() (or to harvest into thin air)
   * is the one move that silently kills every downstream hook. Customize
   * persist(); the harvest is not negotiable.
   *
   * @param IRecordsDomainEvents $aggregate The aggregate to save
   * @return void
   * @throws TypeMismatchException If aggregate type doesn't match expected type
   */
  final public function save(IRecordsDomainEvents $aggregate): void {
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

    // The class check above is what makes this call safe: a subclass that
    // keeps persist(Aggregate) names an Aggregate class.
    $this->persist($aggregate);
    $this->events->collect_from($aggregate);
  }
}
