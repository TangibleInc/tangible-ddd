<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** A listener in the IntegrationTranslator shape (public event_class() + translate()). */
final class ShipOrderListener {

  public function event_class(): string {
    return OrderPlaced::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    /** @var OrderPlaced $event */
    return new RecordingCommand('ship', $event->order_id);
  }
}
