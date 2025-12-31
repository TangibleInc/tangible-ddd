<?php

namespace TangibleDDD\Application\Process;

use ReflectionClass;
use ReflectionMethod;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use Throwable;

/**
 * Executes long-running processes step by step.
 *
 * Responsibilities:
 * - Register event types for process resume
 * - Discover steps via reflection (methods in declaration order)
 * - Execute steps and dispatch returned commands
 * - Suspend on AwaitEvent and persist state
 * - Resume when matching integration event fires
 * - Reschedule via ActionScheduler when resources exhausted or #[Async] step
 * - Run compensations in reverse on failure
 *
 * All entry points (start, continue, resume) flow through the same run() path.
 */
final class ProcessRunner {
  use RescheduleAware;

  /** @var array<string, bool> Tracks which events have action hooks registered */
  private array $registered_events = [];

  /** @var IIntegrationEvent|null Transient - event that triggered current resume */
  private ?IIntegrationEvent $resume_event = null;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly IProcessRepository $repository,
  ) {}

  // ─────────────────────────────────────────────────────────────────────────
  // Public API
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Register a process class.
   *
   * Currently a no-op - event registration is separate.
   * Kept for API compatibility and potential future use.
   *
   * @return $this For fluent chaining
   */
  public function register(string $process_class): self {
    if (!is_subclass_of($process_class, LongProcess::class)) {
      throw new \InvalidArgumentException("$process_class must extend LongProcess");
    }

    return $this;
  }

  /**
   * Register an event type for process resume.
   *
   * Call this for each event type that processes may await.
   * When the event fires, suspended processes waiting for it will resume.
   *
   * @return $this For fluent chaining
   */
  public function register_event(string $event_class): self {
    if (isset($this->registered_events[$event_class])) {
      return $this;
    }

    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new \InvalidArgumentException("$event_class must implement IIntegrationEvent");
    }

    $this->registered_events[$event_class] = true;

    add_action(
      $event_class::integration_action(),
      fn(array $payload) => $this->resume_on_event($event_class::from_payload($payload)),
      99 // Late priority - run after main handlers
    );

    return $this;
  }

  /**
   * Start a new process.
   */
  public function start(LongProcess $process): void {
    $steps = $this->create_process_steps($process);
    $process->start(CorrelationContext::get(), $steps);
    $this->repository->save($process);

    $this->run($process);
  }

  /**
   * Continue a scheduled process (called from ActionScheduler).
   */
  public function continue_scheduled(int $process_id): void {
    $process = $this->repository->find($process_id);

    if ($process === null) {
      return; // Process was deleted
    }

    if ($process->status() === 'completed' || $process->status() === 'failed') {
      return; // Already finished
    }

    CorrelationContext::init($process->correlation_id());

    $process->advance(status: 'running', payload: $process->payload());
    $this->repository->save($process);

    $this->run($process);
  }

  /**
   * Resume suspended processes when an integration event fires.
   */
  public function resume_on_event(IIntegrationEvent $event): void {
    $event_class = get_class($event);
    $waiting = $this->repository->find_waiting_for($event_class);

    foreach ($waiting as $process) {
      $criteria = $process->match_criteria() ?? [];

      if (!$this->event_matches_criteria($event, $criteria)) {
        continue;
      }

      CorrelationContext::init($process->correlation_id());

      // The awaited event arrived - complete the waiting step and move forward
      $process->advance_step();
      $this->resume_event = $event; // Store for next step to receive
      $process->advance(status: 'running', payload: $process->payload());
      $this->repository->save($process);

      $this->run($process);

      // Clear transient state
      $this->resume_event = null;

      // Only resume first matching process per event
      // (if you need fan-out, remove this return)
      return;
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Unified execution
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Run the process from its current state.
   *
   * This is the single entry point for all execution paths.
   * Handles both forward execution and compensation.
   */
  private function run(LongProcess $process): void {
    $this->started_at = time();

    try {
      if ($process->is_compensating()) {
        $this->execute_compensation($process);
      } else {
        $this->execute_forward($process);
      }
    } catch (Throwable $e) {
      // Ensure we don't leave process in inconsistent state
      if ($process->status() !== 'failed') {
        $process->fail($e->getMessage());
        $this->repository->save($process);
      }
      throw $e;
    }
  }

  /**
   * Execute forward steps until completion, suspension, or reschedule.
   */
  private function execute_forward(LongProcess $process): void {
    $reflection = new ReflectionClass($process);

    while (!$process->is_steps_complete()) {
      $step_name = $process->current_step_name();
      if ($step_name === null) {
        break;
      }

      $method = $reflection->getMethod($step_name);

      // #[Async] attribute forces reschedule before execution
      if ($this->has_async_attribute($method)) {
        $this->schedule_continuation($process);
        return;
      }

      try {
        $result = $this->execute_step($process, $method);

        if (!($result instanceof Result)) {
          throw new \RuntimeException(
            "Step {$step_name} must return a Result, got " . get_debug_type($result)
          );
        }

        $this->dispatch_commands($result);

        // Check for event-based suspension
        if ($result->should_suspend()) {
          $this->suspend_for_event($process, $result);
          return;
        }

        // Record checkpoint for potential compensation
        $process->record_checkpoint($result->checkpoint);

        // Advance to next step
        $process->advance_step();
        $process->advance(status: 'running', payload: $result->payload);
        $this->resume_event = null; // Clear after first step post-resume
        $this->repository->save($process);

        // Check resources after each step
        if (!$process->is_steps_complete() && $this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (Throwable $e) {
        // Enter compensation mode
        $process->begin_compensation($e->getMessage());
        $this->repository->save($process);
        $this->execute_compensation($process);
        return;
      }
    }

    // All steps completed
    $process->complete();
    $this->repository->save($process);
  }

  /**
   * Execute compensations in reverse order for completed steps.
   */
  private function execute_compensation(LongProcess $process): void {
    $reflection = new ReflectionClass($process);

    $cause = new \RuntimeException(
      $process->failure_message()
        ? "Process failed at {$process->failed_step()}: {$process->failure_message()}"
        : 'Process failed'
    );

    while (!$process->is_compensation_complete()) {
      $step_name = $process->current_undo_step();

      if ($step_name === null) {
        $process->advance_compensation();
        $this->repository->save($process);
        continue;
      }

      $comp_method_name = $process->compensation_for($step_name);

      // No compensation registered - skip
      if ($comp_method_name === null) {
        $process->advance_compensation();
        $this->repository->save($process);
        continue;
      }

      $method = $reflection->getMethod($comp_method_name);

      // #[Async] on compensation method
      if ($this->has_async_attribute($method)) {
        $this->schedule_continuation($process);
        return;
      }

      try {
        $checkpoint = $process->checkpoint_for($step_name);
        $result = $method->invoke($process, $cause, $checkpoint);

        $this->dispatch_commands($result);

        if ($result->should_suspend()) {
          $this->suspend_for_event($process, $result);
          return;
        }

        $process->advance(status: 'running', payload: $result->payload);
        $process->advance_compensation();
        $this->repository->save($process);

        if ($this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (Throwable $e) {
        // Compensation failed - mark as failed and re-throw
        $process->fail('Compensation failed: ' . $e->getMessage());
        $this->repository->save($process);
        throw $e;
      }
    }

    // All compensations complete
    $process->finish_compensation();
    $this->repository->save($process);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Step execution
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Execute a single step method.
   *
   * Supports three signatures:
   * - step(): Result - no params
   * - step($payload): Result - receives payload
   * - step($payload, $event): Result - receives payload + event (post-await)
   */
  private function execute_step(LongProcess $process, ReflectionMethod $step): mixed {
    $params = $step->getParameters();
    $param_count = count($params);

    if ($param_count === 0) {
      return $step->invoke($process);
    }

    if ($param_count === 1) {
      return $step->invoke($process, $process->payload());
    }

    // 2+ params: pass payload and event
    return $step->invoke($process, $process->payload(), $this->resume_event);
  }

  /**
   * Dispatch commands from a Result (fire-and-forget side effects).
   */
  private function dispatch_commands(Result $result): void {
    foreach ($result->commands as $command) {
      $command->send();
    }
  }

  /**
   * Suspend process waiting for an integration event.
   */
  private function suspend_for_event(LongProcess $process, Result $result): void {
    $await = $result->await;

    $process->advance(
      status: 'suspended',
      payload: $result->payload,
      waiting_for: $await->event_class,
      match_criteria: $await->match_criteria,
    );
    $this->repository->save($process);
  }

  /**
   * Schedule process continuation via ActionScheduler.
   */
  private function schedule_continuation(LongProcess $process): void {
    $process->advance(status: 'scheduled', payload: $process->payload());
    $this->repository->save($process);

    as_enqueue_async_action(
      $this->config->hook('process_continue'),
      ['process_id' => $process->get_id()],
      $this->config->as_group('processes')
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Reflection helpers
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Create ProcessSteps VO from reflection.
   */
  private function create_process_steps(LongProcess $process): ProcessSteps {
    $step_methods = $this->reflect_steps($process);
    $compensations = $this->reflect_compensations(get_class($process));

    return ProcessSteps::from_reflection($step_methods, $compensations);
  }

  /**
   * Reflect step methods from a process class.
   *
   * Steps are protected methods that return Result, excluding compensation methods.
   *
   * @return ReflectionMethod[]
   */
  private function reflect_steps(LongProcess $process): array {
    $reflection = new ReflectionClass($process);
    $methods = [];

    $compensations = $this->reflect_compensations($reflection->getName());

    foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
      // Skip methods from base class
      if ($method->getDeclaringClass()->getName() === LongProcess::class) {
        continue;
      }

      // Skip compensation methods
      if (in_array($method->getName(), $compensations, true)) {
        continue;
      }

      // Must return Result
      $return_type = $method->getReturnType();
      if ($return_type === null || $return_type->getName() !== Result::class) {
        continue;
      }

      $methods[] = $method;
    }

    // Sort by line number (declaration order)
    usort($methods, fn($a, $b) => $a->getStartLine() <=> $b->getStartLine());

    return $methods;
  }

  /**
   * Build compensation map: forward step name => compensation method name.
   *
   * @return array<string, string>
   */
  private function reflect_compensations(string $process_class): array {
    $reflection = new ReflectionClass($process_class);
    $map = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
      if ($method->getDeclaringClass()->getName() === LongProcess::class) {
        continue;
      }

      $attrs = $method->getAttributes(Compensates::class);
      if (empty($attrs)) {
        continue;
      }

      /** @var Compensates $attr */
      $attr = $attrs[0]->newInstance();

      $return_type = $method->getReturnType();
      if ($return_type === null || $return_type->getName() !== Result::class) {
        throw new \RuntimeException(
          "Compensation method {$process_class}::{$method->getName()} must return Result"
        );
      }

      $map[$attr->step] = $method->getName();
    }

    return $map;
  }

  /**
   * Check if a method has the #[Async] attribute.
   */
  private function has_async_attribute(ReflectionMethod $method): bool {
    return !empty($method->getAttributes(Async::class));
  }

  /**
   * Check if an event matches the stored criteria (strict comparison).
   */
  private function event_matches_criteria(IIntegrationEvent $event, array $criteria): bool {
    foreach ($criteria as $key => $expected) {
      if (!property_exists($event, $key)) {
        return false;
      }
      if ($event->$key !== $expected) {
        return false;
      }
    }
    return true;
  }
}
