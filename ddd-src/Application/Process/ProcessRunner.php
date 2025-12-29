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
 * - Register process classes and wire event handlers
 * - Discover steps via reflection (methods in declaration order)
 * - Execute steps and dispatch returned commands
 * - Suspend on AwaitEvent and persist state
 * - Resume when matching integration event fires
 * - Reschedule via ActionScheduler when resources exhausted or #[Async] step
 */
final class ProcessRunner {
  use RescheduleAware;

  /** @var array<string, array<string, string>> event_class => [process_class => method_name] */
  private array $event_handlers = [];

  /** @var array<string, bool> Tracks which events have action hooks registered */
  private array $registered_actions = [];

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly IProcessRepository $repository,
  ) {}

  /**
   * Register a process class.
   *
   * Scans for event handler methods and wires up integration event listeners.
   * Call this at boot time for each process class.
   *
   * @return $this For fluent chaining
   */
  public function register(string $process_class): self {
    if (!is_subclass_of($process_class, LongProcess::class)) {
      throw new \InvalidArgumentException("$process_class must extend LongProcess");
    }

    $reflection = new ReflectionClass($process_class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
      $event_class = $this->get_event_type_from_method($method);

      if ($event_class === null) {
        continue;
      }

      // Map: when EventX fires, ProcessY handles it with method Z
      $this->event_handlers[$event_class][$process_class] = $method->getName();

      // Wire up the action handler (once per event type)
      $this->ensure_action_registered($event_class);
    }

    return $this;
  }

  /**
   * Get the integration event type from a method signature, if any.
   */
  private function get_event_type_from_method(ReflectionMethod $method): ?string {
    $params = $method->getParameters();

    if (count($params) !== 1) {
      return null;
    }

    $type = $params[0]->getType();
    if ($type === null || $type->isBuiltin()) {
      return null;
    }

    $type_name = $type->getName();

    if (!is_a($type_name, IIntegrationEvent::class, true)) {
      return null;
    }

    return $type_name;
  }

  /**
   * Register an action handler for an event type (idempotent).
   */
  private function ensure_action_registered(string $event_class): void {
    if (isset($this->registered_actions[$event_class])) {
      return;
    }

    $this->registered_actions[$event_class] = true;

    // Hook into the integration event system with late priority
    // Consumer provides integration_action helper that calls this
    add_action(
      $event_class::integration_action(),
      function(array $payload) use ($event_class): void {
        $event = $event_class::from_payload($payload);
        $this->resume_on_event($event);
      },
      99  // Late priority - run after main handlers
    );
  }

  /**
   * Start a new process.
   */
  public function start(LongProcess $process): void {
    $this->started_at = time();
    $process->start(CorrelationContext::get());
    $this->repository->save($process);
    $this->execute($process);
  }

  /**
   * Continue a scheduled process (called from ActionScheduler).
   */
  public function continue_scheduled(int $process_id): void {
    $process = $this->repository->find($process_id);

    if ($process === null) {
      throw new \RuntimeException("Process $process_id not found");
    }

    if ($process->status() === 'completed' || $process->status() === 'failed') {
      return; // Already finished
    }

    // Restore correlation context
    CorrelationContext::init($process->correlation_id());

    $this->started_at = time();
    $process->advance(
      next_step: $process->current_step(),
      status: 'running',
      payload: $process->payload(),
    );
    $this->repository->save($process);
    $this->execute($process);
  }

  /**
   * Resume a process when an integration event fires.
   */
  public function resume_on_event(IIntegrationEvent $event): void {
    $event_class = get_class($event);
    $waiting = $this->repository->find_waiting_for($event_class);

    foreach ($waiting as $process) {
      $criteria = $process->match_criteria() ?? [];

      if ($this->event_matches_criteria($event, $criteria)) {
        // Restore correlation context
        CorrelationContext::init($process->correlation_id());

        // Find the handler method for this event
        $handler = $this->find_event_handler($process, $event_class);

        if ($handler !== null) {
          $this->run_from_handler($process, $handler, $event);
        }

        // Only one process should match per event
        return;
      }
    }
  }

  /**
   * Execute process steps until completion, suspension, or reschedule.
   */
  private function execute(LongProcess $process): void {
    $steps = $this->reflect_steps($process);
    $step_count = count($steps);

    while ($process->current_step() < $step_count) {
      $step = $steps[$process->current_step()];

      // #[Async] attribute forces reschedule before execution
      if ($this->has_async_attribute($step)) {
        $this->schedule_continuation($process);
        return;
      }

      try {
        $result = $this->execute_step($process, $step);
        $query_results = $this->dispatch_queries_and_commands($result);

        // Check for event-based suspension
        if ($result->should_suspend()) {
          $this->suspend_for_event($process, $result);
          return;
        }

        $next_payload = !empty($query_results) ? $query_results : $result->payload;

        // Advance to next step
        $process->advance(
          next_step: $process->current_step() + 1,
          status: 'running',
          payload: $next_payload,
        );
        $this->repository->save($process);

        // Check resources after each step - reschedule if exhausted
        if ($process->current_step() < $step_count && $this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (Throwable $e) {
        $process->fail($e->getMessage());
        $this->repository->save($process);
        throw $e;
      }
    }

    // All steps completed
    $process->complete();
    $this->repository->save($process);
  }

  /**
   * Schedule process continuation via ActionScheduler.
   */
  private function schedule_continuation(LongProcess $process): void {
    $process->advance(
      next_step: $process->current_step(),
      status: 'scheduled',
      payload: $process->payload(),
    );
    $this->repository->save($process);

    as_enqueue_async_action(
      $this->config->hook('process_continue'),
      ['process_id' => $process->get_id()],
      $this->config->as_group('processes')
    );
  }

  /**
   * Execute queries and commands from a Result.
   *
   * @return array Query results (empty if no queries)
   */
  private function dispatch_queries_and_commands(Result $result): array {
    $query_results = [];

    if ($result->has_queries()) {
      foreach ($result->queries as $query) {
        $query_results[] = $query->send();
      }
    }

    foreach ($result->commands as $command) {
      $command->send();
    }

    return $query_results;
  }

  /**
   * Suspend process waiting for an integration event.
   */
  private function suspend_for_event(LongProcess $process, Result $result): void {
    $await = $result->await;
    $process->advance(
      next_step: $process->current_step(),
      status: 'suspended',
      payload: $result->payload,
      waiting_for: $await->event_class,
      match_criteria: $await->match_criteria,
    );
    $this->repository->save($process);
  }

  /**
   * Check if a method has the #[Async] attribute.
   */
  private function has_async_attribute(ReflectionMethod $method): bool {
    $attributes = $method->getAttributes(Async::class);
    return !empty($attributes);
  }

  /**
   * Run from a specific event handler method.
   */
  private function run_from_handler(LongProcess $process, ReflectionMethod $handler, IIntegrationEvent $event): void {
    $steps = $this->reflect_steps($process);

    try {
      // Execute the handler with the event
      $result = $handler->invoke($process, $event);
      $query_results = $this->dispatch_queries_and_commands($result);

      // Check for another suspension
      if ($result->should_suspend()) {
        $this->suspend_for_event($process, $result);
        return;
      }

      $next_payload = !empty($query_results) ? $query_results : $result->payload;

      // Find handler's position in steps and continue from next
      $handler_index = $this->find_step_index($steps, $handler->getName());
      $process->advance(
        next_step: $handler_index + 1,
        status: 'running',
        payload: $next_payload,
      );
      $this->repository->save($process);

      // Continue with remaining steps
      $this->execute($process);

    } catch (Throwable $e) {
      $process->fail($e->getMessage());
      $this->repository->save($process);
      throw $e;
    }
  }

  /**
   * Execute a single step method.
   */
  private function execute_step(LongProcess $process, ReflectionMethod $step): Result {
    $params = $step->getParameters();

    if (empty($params)) {
      return $step->invoke($process);
    }

    // Pass payload to step
    $payload = $process->payload();
    return $step->invoke($process, $payload);
  }

  /**
   * Reflect step methods from a process class.
   * Returns methods in source declaration order.
   *
   * @return ReflectionMethod[]
   */
  private function reflect_steps(LongProcess $process): array {
    $reflection = new ReflectionClass($process);
    $methods = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
      // Skip methods from base class
      if ($method->getDeclaringClass()->getName() === LongProcess::class) {
        continue;
      }

      // Skip methods that receive integration events (they're handlers, not steps)
      if ($this->is_event_handler($method)) {
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
   * Check if a method is an event handler (receives IIntegrationEvent).
   */
  private function is_event_handler(ReflectionMethod $method): bool {
    $params = $method->getParameters();

    if (count($params) !== 1) {
      return false;
    }

    $type = $params[0]->getType();
    if ($type === null) {
      return false;
    }

    $type_name = $type->getName();
    return is_a($type_name, IIntegrationEvent::class, true);
  }

  /**
   * Find an event handler method for a given event class.
   */
  private function find_event_handler(LongProcess $process, string $event_class): ?ReflectionMethod {
    $reflection = new ReflectionClass($process);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
      $params = $method->getParameters();

      if (count($params) !== 1) {
        continue;
      }

      $type = $params[0]->getType();
      if ($type === null) {
        continue;
      }

      if ($type->getName() === $event_class) {
        return $method;
      }
    }

    return null;
  }

  /**
   * Find step index by method name.
   */
  private function find_step_index(array $steps, string $method_name): int {
    foreach ($steps as $index => $step) {
      if ($step->getName() === $method_name) {
        return $index;
      }
    }
    return 0;
  }

  /**
   * Check if an event matches the stored criteria.
   */
  private function event_matches_criteria(IIntegrationEvent $event, array $criteria): bool {
    foreach ($criteria as $key => $expected) {
      if (!property_exists($event, $key)) {
        return false;
      }
      if ($event->$key != $expected) {
        return false;
      }
    }
    return true;
  }
}
