<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

use Closure;
use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

/**
 * Captures the result of executing a behaviour step (or phase within a saga).
 *
 * This is designed to be JSON-serializable and persisted in the workflow table.
 */
final class BehaviourExecutionResult extends DirectJsonLifecycleValue {

  /**
   * @param string $type Behaviour type
   * @param bool $success
   * @param array $context Arbitrary context (message/details)
   * @param BehaviourExecutionStatus $status
   * @param string|null $timestamp ISO-8601 UTC time (defaults to now)
   * @param int $phase Saga phase (1-based)
   * @param BehaviourExecutionResult[] $history
   * @param array $batch_success
   * @param array $batch_error
   */
  public function __construct(
    public readonly string $type,
    public readonly bool $success,
    public readonly array $context,
    public readonly BehaviourExecutionStatus $status,
    public readonly ?string $timestamp = null,
    public readonly int $phase = 1,
    public readonly array $history = [],
    public readonly array $batch_success = [],
    public readonly array $batch_error = []
  ) {
    parent::__construct();
  }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;

    return new self(
      type: (string) ($data->type ?? ''),
      success: (bool) ($data->success ?? false),
      context: isset($data->context) ? json_decode(json_encode($data->context), true) : [],
      status: BehaviourExecutionStatus::from((string) ($data->status ?? BehaviourExecutionStatus::failed->value)),
      timestamp: (string) ($data->timestamp ?? gmdate('c')),
      phase: (int) ($data->phase ?? 1),
      history: array_map(
        static fn($history) => self::from_json_instance($history),
        $data->history ?? []
      ),
      batch_success: isset($data->batch_success) ? (array) $data->batch_success : [],
      batch_error: isset($data->batch_error) ? (array) $data->batch_error : [],
    );
  }

  /**
   * Append this result into history and return a new "followed-up" result chain.
   */
  public function follow_up(BehaviourExecutionResult $result): BehaviourExecutionResult {
    $new_history = $this->history;

    array_unshift($new_history, new self(
      type: $this->type,
      success: $this->success,
      context: $this->context,
      status: $this->status,
      timestamp: $this->timestamp,
      phase: $this->phase,
      history: [], // Do not nest histories
      batch_success: $this->batch_success,
      batch_error: $this->batch_error
    ));

    return new self(
      type: $result->type,
      success: $result->success,
      context: $result->context,
      status: $result->status,
      timestamp: $result->timestamp,
      phase: $result->phase,
      history: $new_history,
      batch_success: $result->batch_success,
      batch_error: $result->batch_error
    );
  }

  public function get_count_retries(): int {
    return $this->get_count_status(BehaviourExecutionStatus::failed);
  }

  public function exceeded_batch_runs(int $total_size, int $batch_size): bool {
    return $this->get_count_status(BehaviourExecutionStatus::batched) * $batch_size > $total_size * 1.4;
  }

  private function get_count_status(BehaviourExecutionStatus $status): int {
    $cnt = (int) ($this->status === $status);
    foreach ($this->history as $result) {
      $cnt += $result->get_count_status($status);
    }
    return $cnt;
  }

  public function get_next_phase(): int {
    return $this->status !== BehaviourExecutionStatus::failed ? $this->phase + 1 : $this->phase;
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // NOTE: batch_success and batch_error are kept for AUDIT PURPOSES ONLY.
  //
  // With the work item ledger, operational logic should query items directly:
  //   - items->done()   instead of get_all_success_batch()
  //   - items->failed() instead of get_all_error_batch()
  //
  // The batch arrays in results provide a historical audit trail of what
  // happened in each chunk execution, useful for debugging and support.
  // ─────────────────────────────────────────────────────────────────────────────

  /**
   * Builder to produce execution results with consistent type + phase.
   */
  public static function builder(BaseBehaviourConfig $config, int $phase = 1): Closure {
    return static function(
      bool $success,
      string|array $details,
      BehaviourExecutionStatus $status,
      int $phase_override = 0,
    ) use ($config, $phase): BehaviourExecutionResult {
      return new BehaviourExecutionResult(
        type: $config->get_behaviour_type(),
        success: $success,
        context: is_array($details) ? $details : ['message' => $details],
        status: $status,
        phase: $phase_override ?: $phase,
        timestamp: gmdate('c'),
      );
    };
  }

  public static function builder_batched(BaseBehaviourConfig $config, int $phase = 1): Closure {
    return static function(
      bool $success,
      string $message,
      BehaviourExecutionStatus $status,
      array $batch_success = [],
      array $batch_error = [],
    ) use ($config, $phase): BehaviourExecutionResult {
      return new BehaviourExecutionResult(
        type: $config->get_behaviour_type(),
        success: $success,
        context: ['message' => $message],
        status: $status,
        phase: $phase,
        timestamp: gmdate('c'),
        batch_success: $batch_success,
        batch_error: $batch_error
      );
    };
  }
}


