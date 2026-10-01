<?php

namespace TangibleDDD\Application\Correlation;

/**
 * The node currently executing, as a coordinate: everything born inside its
 * scope records this pair as its parent. The id is always BORROWED — a
 * command_id, an event_id, or a process id; no span ids are ever minted.
 *
 * $label is the 0.2.4 execution frames' ghost: class names for guard
 * exception messages ("name both parties"). Diagnostics ONLY — never
 * persisted, never serialized; envelopes don't carry causes at all (the
 * raiser edge is at rest in outbox.command_id; drains derive fresh causes).
 */
final class Cause {

  public function __construct(
    public readonly string $id,
    public readonly Kind $kind,
    public readonly ?string $label = null,
  ) {}

  /**
   * The at-rest dialect: causation_type columns keep their legacy values
   * forever (ruling: map in projection — columns outlive fashions).
   */
  public function causation_type(): string {
    return match ($this->kind) {
      Kind::Fact => 'integration_event',
      Kind::Trajectory => 'long_process',
      Kind::Act => 'command',
    };
  }
}
