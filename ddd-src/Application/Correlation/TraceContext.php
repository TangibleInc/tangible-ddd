<?php

namespace TangibleDDD\Application\Correlation;

/**
 * The immutable propagated value (0.3, spec §4) — W3C-exact name: the Trace
 * Context spec is a PROPAGATION standard, natively write-side.
 *
 *   correlation_id — which story (field spelling matches the columns)
 *   cause          — the node we're inside; null = flat context
 *   sequence       — story position (per-story Lamport clock)
 *
 * Derivations return copies with ONLY the cause swapped: same story, same
 * position, new "you are here" marker. A node's parent is
 * Correlation::current()->cause at its birth — four stamp sites, one rule.
 */
final class TraceContext {

  public function __construct(
    public readonly string $correlation_id,
    public readonly ?Cause $cause = null,
    public readonly int $sequence = 0,
  ) {}

  /** A new story: no cause, position zero, coordination-free UUID v4. */
  public static function root(): self {
    return new self(\TangibleDDD\Domain\Shared\Uuid::v4());
  }

  public function for_act(string $command_id, ?string $label = null): self {
    return new self($this->correlation_id, new Cause($command_id, Kind::Act, $label), $this->sequence);
  }

  public function for_fact(string $event_id, ?string $label = null): self {
    return new self($this->correlation_id, new Cause($event_id, Kind::Fact, $label), $this->sequence);
  }

  public function for_trajectory(string $process_id, ?string $label = null): self {
    return new self($this->correlation_id, new Cause($process_id, Kind::Trajectory, $label), $this->sequence);
  }

}
