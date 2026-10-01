<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A cancellation fact (D3 any-of): matched by criteria, not keyed. */
final class AppDestroyScheduled extends IntegrationEvent {

  public function __construct(public readonly int $app_id = 1) {}

  protected static function prefix(): string {
    return 'acme';
  }
}
