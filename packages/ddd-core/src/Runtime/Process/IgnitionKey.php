<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

use TangibleDDD\Runtime\Ids\NameBasedUuid;

/**
 * The ignition dedup key (X7, frozen): `uuid5(event_id, process_class)`.
 * Set ONLY by the #[StartsOn] ignition path; manual starts leave it NULL.
 */
final class IgnitionKey {

  /** @throws \InvalidArgumentException when $event_id is not a UUID */
  public static function for(string $event_id, string $process_class): string {
    return NameBasedUuid::v5($event_id, $process_class);
  }
}
