<?php

namespace TangibleDDD\Application\Process;

use DateTimeImmutable;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;

/**
 * Base class for long-running business processes.
 *
 * Processes are defined as a series of steps (methods) that execute in declaration order.
 * Each step returns a Result that tells the runner what to do next:
 * - payload: pass data to the next step (must be JsonLifecycleValue)
 * - queries: execute these queries, pass results to next step
 * - commands: execute these commands (they send() themselves)
 * - await: suspend until an integration event fires
 *
 * Example:
 * ```php
 * class GenerateLearningPath extends LongProcess {
 *   public function __construct(
 *     private readonly int $user_id,
 *   ) {
 *     parent::__construct(null);
 *   }
 *
 *   protected function fetch_history(): Result {
 *     return new Result(payload: new HistoryPayload($this->user_id));
 *   }
 *
 *   protected function request_approval(HistoryPayload $payload): Result {
 *     return new Result(
 *       await: new AwaitEvent(UserApprovedPath::class, ['user_id' => $this->user_id])
 *     );
 *   }
 * }
 * ```
 */
abstract class LongProcess extends Aggregate {

  // ─────────────────────────────────────────────────────────────────────────
  // Framework state (persisted to DB columns)
  // ─────────────────────────────────────────────────────────────────────────

  protected string $status = 'pending';
  protected string $correlation_id;
  protected ?string $waiting_for = null;
  protected ?array $match_criteria = null;
  protected ?string $last_error = null;
  protected ?DateTimeImmutable $created_at = null;
  protected ?DateTimeImmutable $updated_at = null;

  /**
   * Typed payload passed between steps.
   * Serialized with class name for reconstruction.
   */
  protected ?JsonLifecycleValue $payload = null;

  /**
   * Step execution schema and state.
   * Computed once at process start, then frozen.
   */
  protected ?ProcessSteps $steps = null;

  // ─────────────────────────────────────────────────────────────────────────
  // Accessors (framework state)
  // ─────────────────────────────────────────────────────────────────────────

  public function status(): string {
    return $this->status;
  }

  public function waiting_for(): ?string {
    return $this->waiting_for;
  }

  public function match_criteria(): ?array {
    return $this->match_criteria;
  }

  public function payload(): ?JsonLifecycleValue {
    return $this->payload;
  }

  public function correlation_id(): string {
    return $this->correlation_id;
  }

  public function last_error(): ?string {
    return $this->last_error;
  }

  public function created_at(): ?DateTimeImmutable {
    return $this->created_at;
  }

  public function updated_at(): ?DateTimeImmutable {
    return $this->updated_at;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Step state accessors (delegates to ProcessSteps, hides internal structure)
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Get the raw ProcessSteps VO (for persistence only).
   * @internal
   */
  public function steps(): ?ProcessSteps {
    return $this->steps;
  }

  /**
   * Current step index (for persistence/debugging).
   */
  public function current_step_index(): int {
    return $this->steps?->step_index ?? 0;
  }

  /**
   * Current step method name.
   */
  public function current_step_name(): ?string {
    return $this->steps?->current_step();
  }

  /**
   * Check if all forward steps are complete.
   */
  public function is_steps_complete(): bool {
    return $this->steps?->is_complete() ?? true;
  }

  /**
   * Check if process is in compensation mode.
   */
  public function is_compensating(): bool {
    return $this->steps?->is_compensating() ?? false;
  }

  /**
   * Current compensation step method name.
   */
  public function current_undo_step(): ?string {
    return $this->steps?->current_undo_step();
  }

  /**
   * Get compensation method name for a forward step.
   */
  public function compensation_for(string $step_name): ?string {
    return $this->steps?->compensation_for($step_name);
  }

  /**
   * Get checkpoint data for a step (for compensation).
   */
  public function checkpoint_for(string $step_name): ?JsonLifecycleValue {
    return $this->steps?->checkpoint_for($step_name);
  }

  /**
   * Get the step name that failed (triggered compensation).
   */
  public function failed_step(): ?string {
    return $this->steps?->failed_step();
  }

  /**
   * Get the failure message.
   */
  public function failure_message(): ?string {
    return $this->steps?->failure_msg;
  }

  /**
   * Check if compensation is complete (undo_index < 0).
   */
  public function is_compensation_complete(): bool {
    if ($this->steps === null) return true;
    return $this->steps->undo_index < 0;
  }

  /**
   * Find step index by method name.
   */
  public function find_step_index(string $method_name): int {
    if ($this->steps === null) return 0;
    foreach ($this->steps->steps as $index => $name) {
      if ($name === $method_name) {
        return $index;
      }
    }
    return 0;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Lifecycle
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Initialize a new process with correlation ID and step schema.
   * Called by ProcessRunner at start.
   */
  public function start(string $correlation_id, ProcessSteps $steps): void {
    $this->correlation_id = $correlation_id;
    $this->steps = $steps;
    $this->status = 'running';
    $this->created_at = new DateTimeImmutable();
    $this->updated_at = $this->created_at;
  }

  /**
   * Update process state.
   */
  public function advance(
    string $status,
    ?JsonLifecycleValue $payload = null,
    ?string $waiting_for = null,
    ?array $match_criteria = null,
  ): void {
    $this->status = $status;
    $this->payload = $payload;
    $this->waiting_for = $waiting_for;
    $this->match_criteria = $match_criteria;
    $this->updated_at = new DateTimeImmutable();
  }

  /**
   * Move step cursor forward.
   */
  public function advance_step(): void {
    $this->steps?->advance();
  }

  /**
   * Advance step cursor to a specific index.
   */
  public function advance_step_to(int $index): void {
    if ($this->steps === null) return;
    while ($this->steps->step_index < $index) {
      $this->steps->advance();
    }
  }

  /**
   * Record checkpoint for current step (for compensation).
   */
  public function record_checkpoint(?JsonLifecycleValue $checkpoint): void {
    $step_name = $this->steps?->current_step();
    if ($step_name !== null && $checkpoint !== null) {
      $this->steps->record_checkpoint($step_name, $checkpoint);
    }
  }

  /**
   * Enter compensation mode after a failure.
   */
  public function begin_compensation(string $error_message): void {
    $this->steps?->begin_undo($error_message);
  }

  /**
   * Move to next compensation step.
   */
  public function advance_compensation(): void {
    $this->steps?->advance_undo();
  }

  /**
   * Finish compensation and mark process as failed.
   */
  public function finish_compensation(): void {
    $this->steps?->finish_undo();
    $this->fail($this->steps?->failure_msg ?? 'Process failed');
  }

  /**
   * Mark as failed.
   */
  public function fail(string $error): void {
    $this->status = 'failed';
    $this->last_error = $error;
    $this->updated_at = new DateTimeImmutable();
  }

  /**
   * Mark as completed.
   */
  public function complete(): void {
    $this->status = 'completed';
    $this->updated_at = new DateTimeImmutable();
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Hydration (from persistence)
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Hydrate framework state from persistence.
   */
  public function hydrate_state(
    int $id,
    string $status,
    string $correlation_id,
    ?string $waiting_for,
    ?array $match_criteria,
    ?string $last_error,
    ?DateTimeImmutable $created_at,
    ?DateTimeImmutable $updated_at,
  ): void {
    $this->id = $id;
    $this->status = $status;
    $this->correlation_id = $correlation_id;
    $this->waiting_for = $waiting_for;
    $this->match_criteria = $match_criteria;
    $this->last_error = $last_error;
    $this->created_at = $created_at;
    $this->updated_at = $updated_at;
  }

  /**
   * Hydrate ProcessSteps from persistence.
   */
  public function hydrate_steps(ProcessSteps $steps): void {
    $this->steps = $steps;
  }

  /**
   * Hydrate payload from persistence.
   */
  public function hydrate_payload(?JsonLifecycleValue $payload): void {
    $this->payload = $payload;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Serialization (for PHP native serialize/unserialize)
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Serialize process state for suspension.
   * Captures all readonly promoted constructor properties from child class.
   */
  public function __serialize(): array {
    $data = [];
    $reflection = new \ReflectionClass($this);
    $constructor = $reflection->getConstructor();

    if ($constructor !== null) {
      foreach ($constructor->getParameters() as $param) {
        if ($param->isPromoted()) {
          $prop = $reflection->getProperty($param->getName());
          $prop->setAccessible(true);
          $data[$param->getName()] = $prop->getValue($this);
        }
      }
    }

    // Persist ProcessSteps VO
    if ($this->steps !== null) {
      $data['_steps'] = $this->steps->to_json(false);
    }

    // Persist typed payload with class name
    if ($this->payload !== null) {
      $data['_payload'] = JsonLifecycleValue::serialize_polymorphic($this->payload);
    }

    return $data;
  }

  /**
   * Restore process state after resumption.
   */
  public function __unserialize(array $data): void {
    // Restore ProcessSteps
    if (isset($data['_steps'])) {
      $this->steps = ProcessSteps::from_json($data['_steps']);
      unset($data['_steps']);
    }

    // Restore typed payload
    if (isset($data['_payload'])) {
      $this->payload = JsonLifecycleValue::deserialize_polymorphic($data['_payload']);
      unset($data['_payload']);
    }

    // Restore child class properties
    foreach ($data as $key => $value) {
      $this->$key = $value;
    }
  }
}
