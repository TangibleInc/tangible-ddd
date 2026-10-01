<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** One key of an RbGatherSaga's AwaitAll. */
class RbPartShipped extends IntegrationEvent {

  public function __construct(public readonly string $order = '', public readonly string $part = '') {}

  protected static function prefix(): string {
    return RbConsumer::PREFIX;
  }
}
