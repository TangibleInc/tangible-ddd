<?php

namespace TangibleDDD\Domain\Repositories;

use TangibleDDD\Domain\BehaviourWorkflow;

interface IBehaviourWorkflowRepository {
  public function get_by_id(int $id): BehaviourWorkflow;

  /**
   * @return BehaviourWorkflow[]
   */
  public function get_by_ref_id(int $ref_id, string $ref_type): array;

  public function save(BehaviourWorkflow $workflow): void;
}


