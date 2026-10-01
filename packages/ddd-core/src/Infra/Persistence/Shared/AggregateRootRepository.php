<?php

declare(strict_types=1);

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;

/**
 * Identity-agnostic persist-then-harvest repository base (TXP demand L2).
 *
 * The same contract as PersistsAggregatesRepository without the 0.6
 * IPersistsAggregates interface, whose `get_by_id(int): ?Aggregate` an
 * identity-agnostic root cannot satisfy. Lookups are the subclass's own
 * (`find(TeamId $id): ?Team`, whatever fits its identity).
 *
 * save() is FINAL: persist-then-collect is the seal's ground truth.
 * Customise persist(); the harvest is not negotiable.
 */
abstract class AggregateRootRepository {

  public function __construct(
    protected readonly EventsUnitOfWork $events
  ) {}

  /** @return class-string<IRecordsDomainEvents> the root class this repository persists */
  abstract protected function get_aggregate_class(): string;

  abstract protected function persist(IRecordsDomainEvents $aggregate): void;

  /** @throws TypeMismatchException when $aggregate is not of get_aggregate_class() */
  final public function save(IRecordsDomainEvents $aggregate): void {
    $expected = $this->get_aggregate_class();
    if (!$aggregate instanceof $expected) {
      throw new TypeMismatchException(sprintf(
        'Repository %s expected %s, got %s',
        static::class,
        $expected,
        get_class($aggregate)
      ));
    }

    $this->persist($aggregate);
    $this->events->collect_from($aggregate);
  }
}
