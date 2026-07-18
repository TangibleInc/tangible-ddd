<?php

/** Conformance-scanner fixtures: listener thinness. */

namespace TangibleDDD\Tests\Fakes\Conformance;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** The happy path: no ctor, pure fact-in / intention-out translation. */
class ThinListener extends IntegrationListener {
  protected function get_event_class(): string { return ConformantEvent::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

/** VIOLATION: object dependency — work belongs in the command handler. */
class FatListener extends IntegrationListener {
  public function __construct(private readonly FakeRepositoryDependency $repo) {
    parent::__construct();
  }

  protected function get_event_class(): string { return ConformantEvent::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

/** Scalar ctor params (config) are tolerated — only object deps violate. */
class ConfiguredListener extends IntegrationListener {
  public function __construct(private readonly int $threshold = 3) {
    parent::__construct();
  }

  protected function get_event_class(): string { return ConformantEvent::class; }
  protected function get_command(IIntegrationEvent $event): ?ICommand { return null; }
}

final class FakeRepositoryDependency {
}
