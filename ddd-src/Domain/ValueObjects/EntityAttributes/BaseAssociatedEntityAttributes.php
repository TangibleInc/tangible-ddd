<?php

namespace TangibleDDD\Domain\ValueObjects\EntityAttributes;

use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

/**
 * Base value object for "associated entity attributes" stored in separate meta keys.
 *
 * Pattern:
 * - Each associated entity is stored at a distinct meta key: "{$prefix}{$related_id}"
 * - Value is JSON (round-trip safe via JsonLifecycle pattern)
 */
abstract class BaseAssociatedEntityAttributes extends DirectJsonLifecycleValue {

  /**
   * The related entity identifier used to build the meta key suffix.
   */
  abstract public function get_related_id(): int;
}


