<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;

/** D2: subscribes to a marker interface, after the plain listeners. */
#[SubscriberPriority(20)]
final class MarkerListener {

  public function event_class(): string {
    return PingMarker::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new RecordingCommand('marker:' . get_class($event));
  }
}
