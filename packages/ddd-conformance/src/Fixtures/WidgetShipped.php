<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A plain integration record (twin style) for the delivery scenarios. */
final class WidgetShipped extends IntegrationEvent {

  public function __construct(
    public readonly string $widget_id = 'w-1',
    public readonly string $carrier = 'post',
  ) {}

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
