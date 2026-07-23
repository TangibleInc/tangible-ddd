<?php

namespace TangibleDDD\Tests\Fakes\EventingConformance\Dirty;

/**
 * Grep fixture — a consumer repository that clears a freshly-loaded
 * aggregate's diary by calling the harvest verb. Never autoloaded by the
 * conformance scanners (text scan only).
 */
class RehydratingRepository {

  public function get_by_id(int $id): object {
    $aggregate = $this->hydrate($id);
    $aggregate->pull_events(); // the seal-dodge this fixture exists to catch
    return $aggregate;
  }

  private function hydrate(int $id): object {
    return (object) ['id' => $id];
  }
}
