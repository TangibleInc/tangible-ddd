<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** Resumes the RbOrderSaga of its order (AwaitEvent with criteria). */
class RbPaymentReceived extends IntegrationEvent {

  public function __construct(public readonly string $order = '') {}

  protected static function prefix(): string {
    return RbConsumer::PREFIX;
  }
}
