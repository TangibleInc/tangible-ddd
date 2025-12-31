<?php

namespace TangibleDDD\Application\Process;

use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\Domain\Shared\JsonLifecycleValue as JLV;

/**
 * Value Object that captures the execution schema and state of a LongProcess.
 *
 * The `steps` and `compensations` arrays are computed once at process start
 * via reflection, then frozen. This makes processes resilient to code changes:
 * each instance carries its own snapshot of the method structure.
 *
 * If drastic changes are needed (rename methods, restructure flow), create
 * a new process class (e.g., ProcessV2) rather than mutating the existing one.
 */
final class ProcessSteps extends DirectJsonLifecycleValue {

  public function __construct(
    /** @var string[] All step method names in execution order */
    public array $steps = [],

    /** @var array<string, string> step_name => compensation_method_name */
    public array $compensations = [],

    /** @var array<string, array> step_name => serialized checkpoint {_class, _data} */
    public array $checkpoints = [],

    /** Forward execution cursor (index into $steps) */
    public int $step_index = 0,

    /** Compensation cursor (-1 = not compensating, >= 0 = undoing step at this index) */
    public int $undo_index = -1,

    /** Error message when process failed */
    public ?string $failure_msg = null,
  ) {
    parent::__construct();
  }

  /**
   * Create from reflection results (called once at process start).
   *
   * @param \ReflectionMethod[] $step_methods
   * @param array<string, string> $compensation_map step_name => compensation_name
   */
  public static function from_reflection(array $step_methods, array $compensation_map): self {
    return new self(
      steps: array_map(fn($m) => $m->getName(), $step_methods),
      compensations: $compensation_map,
    );
  }

  /**
   * Restore from JSON (implements JsonLifecycleValue contract).
   */
  protected static function from_json_instance(stdClass|array $data, ...$params): static {
    $data = (array) $data;
    return new self(
      steps: $data['steps'] ?? [],
      compensations: $data['compensations'] ?? [],
      checkpoints: $data['checkpoints'] ?? [],
      step_index: $data['step_index'] ?? 0,
      undo_index: $data['undo_index'] ?? -1,
      failure_msg: $data['failure_msg'] ?? null,
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Queries
  // ─────────────────────────────────────────────────────────────────────────

  public function is_compensating(): bool {
    return $this->undo_index >= 0;
  }

  public function is_complete(): bool {
    return $this->step_index >= count($this->steps);
  }

  public function current_step(): ?string {
    return $this->steps[$this->step_index] ?? null;
  }

  public function current_undo_step(): ?string {
    if ($this->undo_index < 0) return null;
    return $this->steps[$this->undo_index] ?? null;
  }

  public function compensation_for(string $step): ?string {
    return $this->compensations[$step] ?? null;
  }

  /**
   * Get checkpoint data for a step (deserialized).
   */
  public function checkpoint_for(string $step): ?JLV {
    $data = $this->checkpoints[$step] ?? null;
    if ($data === null) {
      return null;
    }
    return JLV::deserialize_polymorphic($data);
  }

  public function failed_step(): ?string {
    if (!$this->is_compensating()) return null;
    // The step that failed is at step_index (it never completed)
    return $this->steps[$this->step_index] ?? null;
  }

  public function total_steps(): int {
    return count($this->steps);
  }

  public function completed_count(): int {
    return $this->step_index;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Mutations (forward execution)
  // ─────────────────────────────────────────────────────────────────────────

  public function advance(): void {
    $this->step_index++;
  }

  /**
   * Record checkpoint (serialized) for a step.
   */
  public function record_checkpoint(string $step, JLV $checkpoint): void {
    $this->checkpoints[$step] = JLV::serialize_polymorphic($checkpoint);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Mutations (compensation)
  // ─────────────────────────────────────────────────────────────────────────

  public function begin_undo(string $failure_msg): void {
    $this->undo_index = $this->step_index - 1; // Start at last completed step
    $this->failure_msg = $failure_msg;
  }

  public function advance_undo(): void {
    $this->undo_index--;
  }

  public function finish_undo(): void {
    $this->undo_index = -1;
  }
}
