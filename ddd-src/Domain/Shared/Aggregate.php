<?php

namespace TangibleDDD\Domain\Shared;

/**
 * Base class for aggregate roots.
 *
 * Aggregates are entities that serve as consistency boundaries
 * and can record domain events.
 */
abstract class Aggregate extends Entity implements IRecordsDomainEvents {
  use RecordsDomainEvents;
}
