<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A 0.6-style IntegrationListener. Its constructor has a WordPress side
 * effect, so tests build it with newInstanceWithoutConstructor().
 */
final class LegacyWelcomeListener extends IntegrationListener {

  protected function get_event_class(): string {
    return UserJoined::class;
  }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    /** @var UserJoined $event */
    return new RecordingCommand('welcome', $event->user_id);
  }
}
