<?php

/** Core conformance-scanner fixtures: IntegrationTranslator thinness, no WordPress. */

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Conformance;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Domain\Events\IIntegrationEvent;

class ThinTranslator extends IntegrationTranslator {
  protected function get_event_class(): string { return UserJoined::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

/** VIOLATION: object dependency. */
class FatTranslator extends IntegrationTranslator {
  public function __construct(private readonly TranslatorRepositoryDependency $repo) {}
  protected function get_event_class(): string { return UserJoined::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

class ConfiguredTranslator extends IntegrationTranslator {
  public function __construct(private readonly int $threshold = 3) {}
  protected function get_event_class(): string { return UserJoined::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

final class TranslatorRepositoryDependency {
}
