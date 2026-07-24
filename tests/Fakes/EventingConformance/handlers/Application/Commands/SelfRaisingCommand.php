<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Handlers\Application\Commands;

/**
 * Fence fixture — NEVER autoloaded, judged as text. A self-handling command
 * raising via the inherited act lane: the file neither names the trait nor
 * lives under CommandHandlers/, so only the Application/Commands/ path-scope
 * rule can catch it.
 */
class SelfRaisingCommand /* extends SelfHandlingCommand */ {

  protected function handle(): void {
    $this->event(new \stdClass());
  }
}
