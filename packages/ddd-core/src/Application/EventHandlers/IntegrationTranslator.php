<?php

declare(strict_types=1);

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A stateless automation policy: "whenever [fact], then [intention]."
 *
 * The portable core of the 0.6 IntegrationListener (register 1.4): the same
 * two protected hooks, and no constructor, so constructing one has no side
 * effect on any host. Hosts subscribe it through SubscriptionRegistrar, which
 * reads the public pair below. The WordPress IntegrationListener (ddd-wp)
 * extends this and keeps registering itself from its constructor.
 *
 * The whole job is get_command(): fact in, intention out, null = not my
 * business. All work belongs in the command's handler (audit, retry,
 * causation); a translator only translates.
 */
abstract class IntegrationTranslator {

  /**
   * What this translator subscribes to: a fact class (an IIntegrationEvent)
   * or a D2 marker interface, which need not extend IIntegrationEvent
   * (TXP demand L3). SubscriptionRegistrar accepts both.
   *
   * @return class-string the fact class or marker interface
   */
  abstract protected function get_event_class(): string;

  /** Fact in, intention out. Null = no reaction. */
  abstract protected function get_command(IIntegrationEvent $event): ?ICommand;

  /**
   * @return class-string the fact class or marker interface this translator subscribes to
   */
  final public function event_class(): string {
    return $this->get_event_class();
  }

  /** Error behaviour: whatever get_command() throws propagates unchanged. */
  final public function translate(IIntegrationEvent $event): ?ICommand {
    return $this->get_command($event);
  }
}
