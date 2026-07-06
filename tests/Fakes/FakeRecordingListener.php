<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

class FakeRecordingListener extends IntegrationListener {
  public static ?IIntegrationEvent $received = null;

  protected function get_event_class(): string {
    return FakeResolvedEvent::class;
  }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    self::$received = $event;
    /** @var FakeResolvedEvent $event */
    return new FakeCapturingCommand($event->request_id);
  }
}
