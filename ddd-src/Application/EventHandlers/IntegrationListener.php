<?php

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A stateless automation policy: "whenever [fact], then [intention]."
 *
 * The whole job is get_command() — fact in, intention out, null = not my
 * business. All work belongs in the command's handler (audit, retry,
 * causation); a listener only translates. Auto-wired by namespace convention
 * \Application\IntegrationListeners\ (eager boot constructs via the container,
 * so ctor injection is available to subclasses that need it — the happy path
 * needs nothing).
 */
abstract class IntegrationListener {

  /** @return class-string<IIntegrationEvent> */
  abstract protected function get_event_class(): string;

  /** Fact in, intention out. Null = no reaction. */
  abstract protected function get_command(IIntegrationEvent $event): ?ICommand;

  public function __construct() {
    \TangibleDDD\WordPress\integration_listener(
      static::get_event_class(),
      fn(IIntegrationEvent $event) => $this->get_command($event)
    );
  }
}
