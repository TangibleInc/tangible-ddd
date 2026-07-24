<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Clean;

/**
 * Grep fixture — the blessed hydration shape: construction gated on
 * identity records nothing, so there is no diary to clear and no harvest
 * verb anywhere near consumer code. Also declares its own
 * harvest-verb-named method to prove declarations are not flagged, only
 * call sites.
 */
class IdentityGatedRepository {

  public function get_by_id(int $id): object {
    // Identity-gated hydration: the aggregate is built WITH its id, so its
    // constructor raises nothing. Loading is not occurring.
    return $this->hydrate($id);
  }

  /** A local declaration must not trip the scanner. */
  public function pull_events(): array {
    return [];
  }

  private function hydrate(int $id): object {
    return (object) ['id' => $id];
  }
}
