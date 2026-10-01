<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A `ddd8it` fact whose payload can exceed Action Scheduler's 8000-byte args limit. */
class V8BlobFact extends IntegrationEvent {

  public function __construct(public readonly string $blob = '') {}

  protected static function prefix(): string {
    return 'ddd8it';
  }
}
