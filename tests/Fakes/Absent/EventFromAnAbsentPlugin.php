<?php

declare(strict_types=1);

/**
 * An integration event whose namespace root belongs to a plugin that is NOT
 * registered — i.e. the plugin was deactivated. Deliberately does NOT override
 * prefix(), so resolution goes through ConsumerRegistry::owner_of().
 */

namespace Tangible\DeactivatedPlugin\Domain\Events;

use TangibleDDD\Domain\Events\IntegrationEvent;

final class EventFromAnAbsentPlugin extends IntegrationEvent {
  public function __construct(
    public readonly int $entity_id = 1,
  ) {}
}
