<?php

namespace TangibleDDD\Application\Process;

use DateTimeImmutable;
use TangibleDDD\Domain\Shared\Aggregate;

/**
 * Base class for long-running business processes.
 *
 * Processes are defined as a series of steps (methods) that execute in declaration order.
 * Each step returns a Result that tells the runner what to do next:
 * - payload: pass data to the next step
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
 *     $history = (new GetUserHistory($this->user_id))->send();
 *     return new Result(payload: $history);
 *   }
 *
 *   protected function request_approval(array $history): Result {
 *     return new Result(
 *       await: new AwaitEvent(UserApprovedPath::class, ['user_id' => $this->user_id])
 *     );
 *   }
 *
 *   protected function on_approval(UserApprovedPath $event): Result {
 *     return new Result(commands: [new IssuePath($event->approved_items)]);
 *   }
 * }
 * ```
 */
abstract class LongProcess extends Aggregate {

  protected int $current_step = 0;
  protected string $status = 'pending';
  protected string $correlation_id;
  protected ?string $waiting_for = null;
  protected ?array $match_criteria = null;
  protected mixed $payload = null;
  protected ?string $last_error = null;
  protected ?DateTimeImmutable $created_at = null;
  protected ?DateTimeImmutable $updated_at = null;

  public function current_step(): int {
    return $this->current_step;
  }

  public function status(): string {
    return $this->status;
  }

  public function waiting_for(): ?string {
    return $this->waiting_for;
  }

  public function match_criteria(): ?array {
    return $this->match_criteria;
  }

  public function payload(): mixed {
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

  /**
   * Initialize a new process with correlation ID.
   */
  public function start(string $correlation_id): void {
    $this->correlation_id = $correlation_id;
    $this->status = 'running';
    $this->created_at = new DateTimeImmutable();
    $this->updated_at = $this->created_at;
  }

  /**
   * Advance to next step after execution.
   */
  public function advance(
    int $next_step,
    string $status,
    mixed $payload = null,
    ?string $waiting_for = null,
    ?array $match_criteria = null,
  ): void {
    $this->current_step = $next_step;
    $this->status = $status;
    $this->payload = $payload;
    $this->waiting_for = $waiting_for;
    $this->match_criteria = $match_criteria;
    $this->updated_at = new DateTimeImmutable();
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

  /**
   * Hydrate framework state from persistence.
   */
  public function hydrate_state(
    int $id,
    int $current_step,
    string $status,
    string $correlation_id,
    ?string $waiting_for,
    ?array $match_criteria,
    mixed $payload,
    ?string $last_error,
    ?DateTimeImmutable $created_at,
    ?DateTimeImmutable $updated_at,
  ): void {
    $this->id = $id;
    $this->current_step = $current_step;
    $this->status = $status;
    $this->correlation_id = $correlation_id;
    $this->waiting_for = $waiting_for;
    $this->match_criteria = $match_criteria;
    $this->payload = $payload;
    $this->last_error = $last_error;
    $this->created_at = $created_at;
    $this->updated_at = $updated_at;
  }

  /**
   * Serialize process state for suspension.
   * Captures all readonly promoted constructor properties from child class.
   */
  public function __serialize(): array {
    $data = [];
    $reflection = new \ReflectionClass($this);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
      return $data;
    }

    foreach ($constructor->getParameters() as $param) {
      if ($param->isPromoted()) {
        $prop = $reflection->getProperty($param->getName());
        $prop->setAccessible(true);
        $data[$param->getName()] = $prop->getValue($this);
      }
    }

    return $data;
  }

  /**
   * Restore process state after resumption.
   */
  public function __unserialize(array $data): void {
    foreach ($data as $key => $value) {
      $this->$key = $value;
    }
  }
}
