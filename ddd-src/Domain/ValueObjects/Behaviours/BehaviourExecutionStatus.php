<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/**
 * Small explanation of states and their usages.
 *
 * Cursor semantics (important; see `BehaviourWorkflow::maybe_advance()`):
 * - `waiting` and `batched` are **barrier / in-progress** statuses: the cursor does NOT advance.
 * - `failed` also does NOT advance (runner decides retry/reschedule/fail).
 * - other statuses generally mean "this step is finished" and the cursor may advance.
 *
 * State meanings:
 *
 * - `completed`: behaviour executed successfully.
 * - `waiting`: execution depends on an external signal (usermeta update, form submission, webhook, etc).
 *   The workflow should be resumed by that external event (no polling required).
 * - `batched`: bulk work where not all items are done yet; continue later.
 * - `forked`: (optional) batching variant where failed sub-work is split into a new workflow.
 * - `skipped`: behaviour intentionally skipped (disabled, not applicable, missing config), but the chain continues.
 * - `failed`: impedes advancing and should trigger retry/reschedule until retry limit is reached.
 * - `cancelled`: "strong skip" — for sagas, often used to abort the rest of the saga.
 * - `preempted`: "handoff" status — in Tangible-Cred this was used as an **in-place fork**:
 *   attempt/workflow N stops owning further execution of the behaviour chain and remaining work is transferred
 *   to attempt/workflow N+1 (e.g. retry slots across attempts).
 */
enum BehaviourExecutionStatus: string {
  case completed = 'completed';
  case batched = 'batched';
  case forked = 'forked';

  case waiting = 'waiting';
  case skipped = 'skipped';

  case failed = 'failed';

  case cancelled = 'cancelled';
  case preempted = 'preempted';
}


