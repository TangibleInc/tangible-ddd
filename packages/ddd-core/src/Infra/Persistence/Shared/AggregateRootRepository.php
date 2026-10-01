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
 *
 * remove() is its counterpart (TXP demand L9, wave 5): the same class check,
 * then delete(), then the harvest, so a root that records a fact as it goes
 * away (InviteRevoked, MemberLeft) publishes it with the removal. delete()
 * is not abstract, so a 0.6-era subclass keeps compiling; the default
 * refuses with a \LogicException before anything is harvested.
 */
abstract class AggregateRootRepository {

  public function __construct(
    protected readonly EventsUnitOfWork $events
  ) {}

  /** @return class-string<IRecordsDomainEvents> the root class this repository persists */
  abstract protected function aggregate_class(): string;

  abstract protected function persist(IRecordsDomainEvents $aggregate): void;

  /** @throws TypeMismatchException when $aggregate is not of aggregate_class() */
  final public function save(IRecordsDomainEvents $aggregate): void {
    $this->check($aggregate);
    $this->persist($aggregate);
    $this->events->collect_from($aggregate);
  }

  /**
   * Delete, then harvest the events the root recorded on its way out.
   *
   * @throws TypeMismatchException when $aggregate is not of aggregate_class()
   * @throws \LogicException when the repository does not override delete()
   */
  final public function remove(IRecordsDomainEvents $aggregate): void {
    $this->check($aggregate);
    $this->delete($aggregate);
    $this->events->collect_from($aggregate);
  }

  /** Override to support remove(); the default refuses. */
  protected function delete(IRecordsDomainEvents $aggregate): void {
    throw new \LogicException(sprintf('Repository %s does not support remove(): override delete()', static::class));
  }

  private function check(IRecordsDomainEvents $aggregate): void {
    $expected = $this->aggregate_class();
    if (!$aggregate instanceof $expected) {
      throw new TypeMismatchException(sprintf(
        'Repository %s expected %s, got %s',
        static::class,
        $expected,
        get_class($aggregate)
      ));
    }
  }
}
