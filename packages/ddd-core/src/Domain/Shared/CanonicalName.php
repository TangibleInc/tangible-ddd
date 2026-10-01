<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\Shared;

/**
 * Default IAggregateRoot::canonical_name(): the snake_cased class basename.
 *
 * ⚠️ At-rest names must outlive class names: on the first RENAME of a
 * class that has touches on record, override canonical_name() to pin the
 * historical string, otherwise the biography forks.
 */
trait CanonicalName {

  public static function canonical_name(): string {
    $basename = substr(strrchr('\\' . static::class, '\\'), 1);
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
  }
}
