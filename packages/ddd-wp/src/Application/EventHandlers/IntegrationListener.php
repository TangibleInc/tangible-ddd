<?php

namespace TangibleDDD\Application\EventHandlers;

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
 *
 * Wave 2 (rule R2): the WordPress form, owned by ddd-wp. The translation
 * contract lives in the core IntegrationTranslator; this subclass keeps the
 * 0.6 constructor side effect of registering itself on the integration hook.
 */
abstract class IntegrationListener extends IntegrationTranslator {

  public function __construct() {
    \TangibleDDD\WordPress\integration_listener(
      static::get_event_class(),
      fn(IIntegrationEvent $event) => $this->get_command($event)
    );
  }
}
