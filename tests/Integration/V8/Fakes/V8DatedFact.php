<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A `ddd8it` fact whose payload can stop decoding (an unparsable date). */
class V8DatedFact extends IntegrationEvent {

  public function __construct(public readonly \DateTimeImmutable $at) {}

  protected static function prefix(): string {
    return 'ddd8it';
  }
}
