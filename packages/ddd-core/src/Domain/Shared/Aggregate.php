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

  /**
   * The aggregate's canonical name — the local half of its at-rest identity
   * (the owning consumer's prefix supplies the other half: 'cred.license').
   * Default: the snake_cased class basename.
   *
   * ⚠️ At-rest names must outlive class names: on the first RENAME of a
   * class that has touches on record, override this to pin the historical
   * string — otherwise the biography forks.
   */
  public static function canonical_name(): string {
    $basename = substr(strrchr('\\' . static::class, '\\'), 1);
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
  }
}
