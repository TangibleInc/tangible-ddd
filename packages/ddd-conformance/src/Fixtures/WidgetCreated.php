<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Domain\Events\DomainEvent;

/** A plain (non-announcing) domain event: in-process only, never reaches the outbox. */
final class WidgetCreated extends DomainEvent {

  public function __construct(public readonly string $widget_id) {}

  public function payload(): array {
    return ['widget_id' => $this->widget_id];
  }

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
