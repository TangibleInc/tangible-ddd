<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Handlers\Application\CommandHandlers;

/**
 * Grep fixture — a command handler raising an act-level coordination fact
 * through the blessed trait lane. handler_raised_events() flags it unless
 * the consumer's suite allowlists it as a reviewed decision.
 */
class RescheduleRaisingHandler {

  public function handle(object $command): void {
    $this->event((object) ['name' => 'routine_rescheduled']);
  }

  private function event(object $e): void {}
}
