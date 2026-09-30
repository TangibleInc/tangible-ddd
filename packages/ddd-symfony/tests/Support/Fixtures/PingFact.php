<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A minimal fact with a stamped prefix (needs no ConsumerRegistry). */
final class PingFact extends IntegrationEvent implements PingMarker {

  public function __construct(public readonly int $n = 0) {}

  protected static function prefix(): string {
    return 'sft';
  }
}
