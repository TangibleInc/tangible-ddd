<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** PackWidgetProcess waits for it (AwaitEvent on widget_id). */
final class WidgetPacked extends IntegrationEvent {

  public function __construct(public readonly string $widget_id = 'w-1') {}

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
