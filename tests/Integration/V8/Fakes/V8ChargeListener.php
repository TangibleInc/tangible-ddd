<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** A SubscriptionRegistrar listener translating V8Fact into the D1 effect V8Charge (no retries declared). */
class V8ChargeListener {

  public function event_class(): string {
    return V8Fact::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return $event instanceof V8Fact ? new V8Charge($event->n) : null;
  }
}
