<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Handlers\Application\Services;

/**
 * Grep fixture — outside CommandHandlers and never mentions the trait; its
 * call below is OUT of scope for handler_raised_events() (it is some other
 * event() — e.g. an aggregate's own diary verb).
 */
class QuietService {

  public function run(): void {
    $this->event((object) ['name' => 'not_ours']);
  }

  private function event(object $e): void {}
}
