<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** D3: the cancellation fact of an any-of await (matched by criteria, unkeyed). */
final class AppDestroyed extends IntegrationEvent {

  public function __construct(public readonly int $app_id = 1) {}

  protected static function prefix(): string {
    return 'sft';
  }
}
