<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Handlers\Application\EventHandlers;

/**
 * Fence fixture — NEVER autoloaded, judged as text. A domain-event handler
 * raising via the act lane inherited from WordPressActionHandler (0.6.4):
 * the file neither names the trait nor lives under CommandHandlers/ or
 * Commands/, so only the Application/EventHandlers/ path-scope rule can
 * catch it.
 */
class ReactionRaisingHandler /* extends WordPressActionHandler */ {

  public function handle(object $event): void {
    $this->event(new \stdClass());
  }
}
