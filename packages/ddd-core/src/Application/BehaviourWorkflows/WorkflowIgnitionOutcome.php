<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

enum WorkflowIgnitionOutcome: string {
  /** This call claimed the key, saved the workflow and started it. */
  case Ignited = 'ignited';
  /** The key already ignited a workflow; nothing was saved or started. */
  case AlreadyIgnited = 'already_ignited';
  /** workflow_from_fact() returned null; nothing was claimed. */
  case Declined = 'declined';
}
