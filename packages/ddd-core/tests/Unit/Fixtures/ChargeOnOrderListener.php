<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** Translates an order into a D1 external effect that always fails. */
final class ChargeOnOrderListener {

  public function event_class(): string {
    return OrderPlaced::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    /** @var OrderPlaced $event */
    return new ChargeCard($event->order_id);
  }
}
