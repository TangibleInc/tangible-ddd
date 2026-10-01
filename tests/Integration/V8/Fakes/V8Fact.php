<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** An integration fact owned by the `ddd8it` test consumer. */
class V8Fact extends IntegrationEvent {

  public function __construct(public readonly int $n = 1) {}

  protected static function prefix(): string {
    return 'ddd8it';
  }
}
