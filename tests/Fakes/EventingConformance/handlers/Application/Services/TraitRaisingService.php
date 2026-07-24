<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Handlers\Application\Services;

/**
 * Grep fixture — NOT under CommandHandlers, but it names RaisesEvents,
 * so its act-raise call sites are in scope for handler_raised_events().
 */
class TraitRaisingService {

  // use RaisesEvents; (the scanner keys on the name appearing in source)

  public function run(): void {
    $this->event((object) ['name' => 'process_started']);
  }

  private function event(object $e): void {}
}
