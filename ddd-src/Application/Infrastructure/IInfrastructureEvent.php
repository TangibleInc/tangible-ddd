<?php

namespace TangibleDDD\Application\Infrastructure;

/**
 * An infrastructure event — the framework's machinery reporting on itself
 * (a dead-letter, a failed attempt, a failed process/workflow).
 *
 * Unlike a domain integration event, it is fired OUT-OF-BAND (a raw WordPress
 * action, not through the outbox) — because the substrate that would carry it
 * is often the very thing that failed. It still carries enough context for a
 * listener to rejoin the originating trace:
 *
 *   - correlation_id : the trace this happened inside (null = ambient/system-wide)
 *   - causation_id / causation_type : the parent that a reaction should point at
 *     ('integration_event' for a dead/failed event, 'long_process' for a saga;
 *     null when the subject is not a doctrinal causer, e.g. a workflow).
 */
interface IInfrastructureEvent {

  /** Base action name (e.g. 'outbox_dlq'); fired as {prefix}_{action} + tangible_ddd_{action}. */
  public static function action(): string;

  /** The machinery fact this event is about (OutboxEntry, LongProcess, BehaviourWorkflow). */
  public function subject(): mixed;

  /** Trace to rejoin, or null for ambient/system-wide events. */
  public function correlation_id(): ?string;

  /** Parent span id a reaction should be caused by, or null. */
  public function causation_id(): ?string;

  /** 'integration_event' | 'long_process' | null. */
  public function causation_type(): ?string;
}
