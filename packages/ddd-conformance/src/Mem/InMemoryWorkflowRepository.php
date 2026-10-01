<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Testing\InMemoryTransactional;

/**
 * The mem behaviour-workflow store, enlisted in the boundary. Rows hold a
 * serialized copy, so a reader never gets the live instance.
 */
final class InMemoryWorkflowRepository implements IBehaviourWorkflowRepository, InMemoryTransactional {

  /** @var array<int, string> */
  private array $rows = [];

  private int $next = 1;

  public function get_by_id(int $id): BehaviourWorkflow {
    $blob = $this->rows[$id] ?? throw new \RuntimeException("No behaviour workflow #$id");
    return unserialize($blob);
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    $out = [];
    foreach (array_keys($this->rows) as $id) {
      $w = $this->get_by_id($id);
      if ($w->get_ref_id() === $ref_id && $w->get_ref_type() === $ref_type) {
        $out[] = $w;
      }
    }
    return $out;
  }

  public function save(BehaviourWorkflow $workflow): void {
    if ($workflow->get_id() === null) {
      $workflow->set_id($this->next++);
    }
    $this->rows[(int) $workflow->get_id()] = serialize($workflow);
  }

  public function snapshot(): mixed {
    return [$this->rows, $this->next];
  }

  public function restore(mixed $state): void {
    [$this->rows, $this->next] = $state;
  }
}
