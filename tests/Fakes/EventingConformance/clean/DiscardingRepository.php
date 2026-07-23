<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Clean;

/**
 * Grep fixture — the blessed reconstitution shape: discard_events() clears
 * the diary without returning it. Also declares its own harvest-verb-named
 * method to prove declarations are not flagged, only call sites.
 */
class DiscardingRepository {

  public function get_by_id(int $id): object {
    $aggregate = $this->hydrate($id);
    $aggregate->discard_events();
    return $aggregate;
  }

  /** A local declaration must not trip the scanner. */
  public function pull_events(): array {
    return [];
  }

  private function hydrate(int $id): object {
    return (object) ['id' => $id];
  }
}
