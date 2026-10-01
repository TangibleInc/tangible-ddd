<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** Ignites RbOrderSaga (#[StartsOn]). Same class in N and in a 0.6.x winner. */
class RbOrderPlaced extends IntegrationEvent {

  public function __construct(public readonly string $order = '') {}

  protected static function prefix(): string {
    return RbConsumer::PREFIX;
  }
}
