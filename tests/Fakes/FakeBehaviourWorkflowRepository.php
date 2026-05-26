<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;

final class FakeBehaviourWorkflowRepository implements IBehaviourWorkflowRepository {
  /** @var array<int, BehaviourWorkflow> */
  public array $store = [];
  public int $save_count = 0;
  private int $next_id = 1;

  public function get_by_id(int $id): BehaviourWorkflow {
    return $this->store[$id] ?? throw new \RuntimeException("Workflow $id not found");
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    return array_filter($this->store, fn($wf) => $wf->get_ref_id() === $ref_id && $wf->get_ref_type() === $ref_type);
  }

  public function save(BehaviourWorkflow $workflow): void {
    if ($workflow->get_id() === null) {
      $workflow->set_id($this->next_id++);
    }
    $this->store[$workflow->get_id()] = $workflow;
    $this->save_count++;
  }
}
