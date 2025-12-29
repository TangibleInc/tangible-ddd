<?php

namespace TangibleDDD\Domain;

use TangibleDDD\Domain\Exceptions\BusinessConstraintException;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;
use TangibleDDD\Domain\Exceptions\WorkflowException;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\ISagaBehaviour;

/**
 * A persisted behaviour workflow (small orchestration state machine).
 *
 * Framework-generic:
 * - "ref_id/ref_type" point to the thing this workflow is about (request/order/user/etc.)
 * - behaviour configs are polymorphic BaseBehaviourConfig objects
 * - results record execution history
 * - meta is an arbitrary app-owned bag (e.g. attempt_id)
 */
final class BehaviourWorkflow extends Aggregate {

  private int $ref_id;
  private string $ref_type;

  private ?int $root_workflow_id;

  /** @var BaseBehaviourConfig[] */
  private array $behaviour_configs;

  /** @var BehaviourExecutionResult[] */
  private array $behaviour_results;

  private int $current_idx;
  private int $current_phase;

  private bool $is_complete;
  private bool $is_failed;

  /** @var array<string, mixed> */
  private array $meta;

  public function get_ref_id(): int { return $this->ref_id; }
  public function get_ref_type(): string { return $this->ref_type; }

  /** @return BaseBehaviourConfig[] */
  public function get_behaviour_configs(): array { return $this->behaviour_configs; }

  /** @return BehaviourExecutionResult[] */
  public function get_behaviour_results(): array { return $this->behaviour_results; }

  public function get_current_idx(): int { return $this->current_idx; }
  public function get_current_phase(): int { return $this->current_phase; }

  /**
   * @throws WorkflowException
   */
  public function get_current(): BaseBehaviourConfig {
    $this->assert_not_complete();
    return $this->behaviour_configs[$this->current_idx];
  }

  public function get_current_result(): ?BehaviourExecutionResult {
    return $this->behaviour_results[$this->current_idx] ?? null;
  }

  public function get_last_result(): ?BehaviourExecutionResult {
    return end($this->behaviour_results) ?: null;
  }

  /**
   * Advance the workflow cursor after a behaviour result.
   *
   * Returns whether the workflow is complete after advancing.
   *
   * @throws WorkflowException
   */
  public function maybe_advance(BehaviourExecutionResult &$result): bool {
    $this->assert_not_complete();

    if (null !== ($current_result = $this->get_current_result())) {
      $result = $current_result->follow_up($result);
    }
    $this->behaviour_results[$this->current_idx] = $result;

    // Do not advance on failure (runner decides reschedule/retry)
    if (BehaviourExecutionStatus::failed === $result->status) {
      return false;
    }

    if ($this->is_during_saga()) {
      if (BehaviourExecutionStatus::cancelled === $result->status) {
        $this->complete_saga();
      } else {
        $this->current_phase++;
        if ($this->current_phase > $this->get_current()->no_phases()) {
          $this->complete_saga();
        } else {
          return false;
        }
      }
    } else {
      // If batched, we don't advance until the batch gives us the okay to go forward.
      if (BehaviourExecutionStatus::batched !== $result->status) {
        $this->current_idx++;
      }
    }

    if (count($this->behaviour_configs) === $this->current_idx) {
      $this->is_complete = true;
    }

    return $this->is_complete;
  }

  private function complete_saga(): void {
    $this->current_idx++;
    $this->current_phase = 1;
  }

  /**
   * @throws WorkflowException
   */
  public function is_during_saga(): bool {
    return $this->get_current() instanceof ISagaBehaviour;
  }

  public function get_root_workflow_id(): ?int { return $this->root_workflow_id; }
  public function is_forked(): bool { return null !== $this->root_workflow_id; }

  public function is_active(): bool { return !$this->is_complete && !$this->is_failed; }
  public function is_complete(): bool { return $this->is_complete; }
  public function is_failed(): bool { return $this->is_failed; }

  public function fail(): void {
    $this->is_failed = true;
  }

  public function get_meta(string $meta_key, mixed $default = ''): mixed {
    return $this->meta[$meta_key] ?? $default;
  }

  /** @return array<string, mixed> */
  public function get_all_meta(): array {
    return $this->meta;
  }

  /**
   * @throws WorkflowException
   */
  private function assert_not_complete(): void {
    if ($this->is_complete) {
      throw new WorkflowException("Workflow is completed.");
    }
  }

  /**
   * @param BaseBehaviourConfig[] $behaviour_configs
   * @param BehaviourExecutionResult[] $behaviour_results
   * @param array<string,mixed> $meta
   *
   * @throws BusinessConstraintException
   */
  public function __construct(
    ?int $id,
    int $ref_id,
    string $ref_type,
    array $behaviour_configs,
    array $behaviour_results = [],
    int $current_idx = 0,
    int $current_phase = 1,
    bool $is_complete = false,
    bool $is_failed = false,
    array $meta = [],
    ?int $root_workflow_id = null
  ) {
    parent::__construct($id);

    self::assert_array_of($behaviour_configs, BaseBehaviourConfig::class, 'Behaviour Workflow must be an array of BaseBehaviourConfig.');
    self::assert_array_of($behaviour_results, BehaviourExecutionResult::class, 'Behaviour results must be an array of BehaviourExecutionResult.');
    self::assert_array_of(array_keys($meta), 'string', 'Meta key must be a string.');

    $this->ref_id = $ref_id;
    $this->ref_type = $ref_type;
    $this->behaviour_configs = array_values($behaviour_configs);
    $this->behaviour_results = $behaviour_results;
    $this->current_idx = $current_idx;
    $this->current_phase = $current_phase;
    $this->is_complete = $is_complete;
    $this->is_failed = $is_failed;
    $this->meta = $meta;
    $this->root_workflow_id = $root_workflow_id;

    if ($this->is_forked() && count($this->behaviour_configs) > 1) {
      throw new BusinessConstraintException('A forked workflow cannot have more than one behaviour.');
    }
  }

  private static function assert_array_of(array $values, string $type, string $message): void {
    foreach ($values as $v) {
      if ($type === 'string') {
        if (!is_string($v)) {
          throw new TypeMismatchException($message);
        }
        continue;
      }

      if (!$v instanceof $type) {
        throw new TypeMismatchException($message);
      }
    }
  }
}


