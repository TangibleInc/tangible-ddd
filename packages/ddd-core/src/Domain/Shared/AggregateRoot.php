<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\Shared;

/**
 * Identity-agnostic aggregate root base (TXP demand L2).
 *
 * Records domain events and carries a canonical name, and nothing else: no
 * `id` property, no getter, no setter. The root declares its own identity
 * as a named field of whatever type it needs (a uuid string, a preserved
 * legacy bigint), which also keeps ORMs that reject inherited unmapped
 * properties happy.
 *
 * Persist with AggregateRootRepository (or PersistsAggregatesRepository
 * with a widened persist()), whose final save() persists and then harvests
 * the recorded events into the EventsUnitOfWork.
 *
 * The 0.6 int-id `Entity` / `Aggregate` pair is unchanged (R2/R3); both
 * bases implement IAggregateRoot.
 */
abstract class AggregateRoot implements IAggregateRoot {

  use RecordsDomainEvents;
  use CanonicalName;
}
