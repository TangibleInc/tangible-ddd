<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A cancellation fact (D3 any-of), matched by criteria on widget_id, not keyed. */
final class WidgetScrapped extends IntegrationEvent {

  public function __construct(public readonly string $widget_id = 'w-1') {}

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
