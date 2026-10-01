<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** GatherPartsProcess gathers it (AwaitAll keyed by part). */
final class PartArrived extends IntegrationEvent {

  public function __construct(
    public readonly string $widget_id = 'w-1',
    public readonly string $part = 'a',
  ) {}

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
