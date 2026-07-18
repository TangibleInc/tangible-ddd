<?php

namespace TangibleDDD\Application\Process;

use ReflectionClass;
use ReflectionMethod;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Infrastructure\ProcessFailed;
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

  /** @var array<string, array<string, bool>> process class → event class → ignition hook laid */
  private array $registered_starts = [];

  /** @var mixed Transient - resume_argument() output from the mechanism that woke the process */
  private mixed $resume_argument = null;

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
      function (array $payload) use ($event_class) {
        $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap($payload);
        $envelope->restore_context();

        try {
          $event = $event_class::from_payload($envelope->payload);
          if ($envelope->event_id !== null) {
            $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
          }

          $this->resume_on_event($event);
        } finally {
          CorrelationContext::clear_causation();   // drain scope teardown (0.2.5)
        }
      },
      99 // Late priority - run after main handlers
    );

    return $this;
  }

  /**
   * Register an ignition: integration event → new process, via #[StartsOn].
   *
   * A degenerate-in-reverse IntegrationListener: identical drain trigger and
   * unwrap/hydrate/stamp preamble, identical right to decline (from_event
   * returns null — the policy filter), but the reaction persists and stays
   * alive. Ignition does NOT ride the bus: starting a saga is not an act,
   * so no synthetic command pollutes the moment ledger — the process row
   * (with ignited_by_event_id as the causation edge) is the birth record,
   * and the first audit rows of the saga's life are its step commands.
   *
   * Priority 50: after plain listeners (10), before resumes (99) — so an
   * event may ignite saga B before it wakes saga A, deterministically, and
   * a saga that both StartsOn and Awaits one event class has its igniting
   * instance consumed by ignition (the await sees only later arrivals).
   *
   * @return $this For fluent chaining
   */
  public function register_start(string $process_class, string $event_class): self {
    if (isset($this->registered_starts[$process_class][$event_class])) {
      return $this;
    }

    if (!is_subclass_of($process_class, LongProcess::class)) {
      throw new \InvalidArgumentException("$process_class must extend LongProcess");
    }
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new \InvalidArgumentException("$event_class must implement IIntegrationEvent");
    }
    if (!method_exists($process_class, 'from_event')) {
      throw new \InvalidArgumentException(
        "$process_class declares #[StartsOn] but has no static from_event() — the ignition projection is required."
      );
    }

    $this->registered_starts[$process_class][$event_class] = true;

    add_action(
      $event_class::integration_action(),
      function (array $payload) use ($process_class, $event_class) {
        $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap($payload);
        $envelope->restore_context();

        try {
          $event = $event_class::from_payload($envelope->payload);
          if ($envelope->event_id !== null) {
            $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
          }

          $process = $process_class::from_event($event);
          if ($process === null) {
            return; // not my business — the policy declined
          }

          $event_id = $envelope->event_id !== null ? (string) $envelope->event_id : null;
          if ($event_id !== null) {
            if ($this->repository->has_ignition($process_class, $event_id)) {
              return; // replay / redelivery — this fact already ignited its saga
            }
            $process->mark_ignited_by($event_id);
          }
          $process->mark_source('event');

          $this->start($process);
        } finally {
          CorrelationContext::clear_causation();   // drain scope teardown (0.2.5)
        }
      },
      50 // between listeners (10) and resumes (99)
    );

    return $this;
  }

  /**
   * Start a new process — the EDGE door.
   *
   * Legal from flat contexts only: REST controllers, CLI, WP hook closures,
   * the outbox drain (where #[StartsOn] ignition calls it). Inside a command
   * pass it throws: running the first step there would nest the step's
   * dispatched commands inside the caller's bus pass. Handlers announce an
   * integration event instead and let #[StartsOn] react.
   *
   * The first step runs in-band, immediately — same rule as every wake:
   * steps execute wherever ignition or waking legally happens; only awaits
   * and timeouts create hops.
   */
  public function start(LongProcess $process): void {
    if (null !== $inside = CorrelationContext::command_frame()) {
      throw new ProcessStartedInsideCommand(get_class($process), $inside);
    }

    if (null !== $parent = CorrelationContext::process_frame()) {
      throw new ProcessStartedInsideProcess(get_class($process), $parent);
    }

    // The absorb (0.2.5): a manual ->start() inside a drain is a legal-but-
    // dispreferred spelling of event ignition — the armed causation IS the
    // igniting fact, so record the truth instead of a false cold root.
    // (#[StartsOn] stays the better door: it adds dedup and discovery.)
    if (
      $process->ignited_by_event_id() === null
      && CorrelationContext::causation_type() === 'integration_event'
      && null !== $igniter = CorrelationContext::causation_id()
    ) {
      $process->mark_ignited_by($igniter);
      $process->mark_source('event');
    }

    if ($process->source() === null) {
      $process->mark_source((defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli' ? 'cli' : 'web');
    }

    $steps = $this->create_process_steps($process);
    $process->initialize_lifecycle(CorrelationContext::get(), $steps);
    $this->repository->save($process);

    $this->with_process($process, fn () => $this->run($process));
  }

  /**
   * The sealed bracket — every saga wake, any lane (start, continuation,
   * resume, timeout), executes inside it. Owns, in order: the per-process
   * lock (serializes concurrent wakes of one saga), the correlation scope
   * (dispatched commands stay in the saga's trace; scope-exit is worker
   * hygiene — AS reuses one worker for many callbacks), and the process
   * frame (what makes command-inside-process-scope legible to the guards).
   *
   * Deliberately not a pluggable pipeline: the bracket set is fixed and
   * framework-owned. If a real extension case arrives (ops pause, per-
   * consumer wake policy), this body is where it graduates.
   */
  private function with_process(LongProcess $process, callable $work): void {
    $this->with_process_lock($process->get_id(), function () use ($process, $work) {
      CorrelationContext::with($process->correlation_id(), function () use ($process, $work) {
        CorrelationContext::mark_process_frame((string) $process->get_id());
        try {
          $work();
        } finally {
          CorrelationContext::clear_process_frame();
        }
      });
    });
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

    // The sealed bracket: lock (continuations previously ran unlocked and
    // could race a resume on the same saga), correlation scope, frame.
    $this->with_process($process, function () use ($process) {
      $process->advance(status: 'running', payload: $process->payload());
      $this->repository->save($process);

      $this->run($process);
    });
  }

  /**
   * Resume suspended processes when an integration event fires.
   */
  public function resume_on_event(IIntegrationEvent $event): void {
    $event_class = get_class($event);
    $waiting = $this->repository->find_waiting_for($event_class);

    foreach ($waiting as $process) {
      $mechanism = $process->await_mechanism();
      if ($mechanism === null || !$mechanism->accepts($event)) {
        continue;
      }

      // Whole accepting-process block rides the sealed bracket. A partial
      // arrival now updates its tally inside the saga's correlation scope
      // too — harmless, and one bracket beats two topologies.
      $this->with_process($process, function () use ($process, $event, $mechanism) {
        $updated = $mechanism->accumulate($event);

        if (!$updated->is_satisfied()) {
          // Partial arrival: persist the tally, stay suspended.
          $process->update_await($updated);
          $this->repository->save($process);
          return;
        }

        $process->advance_step();
        $this->resume_argument = $updated->resume_argument($event);
        $process->advance(status: 'running', payload: $process->payload());
        $this->repository->save($process);

        $this->run($process);

        $this->resume_argument = null;
      });

      // Only resume first accepting process per event (key sets are disjoint by construction).
      return;
    }
  }

  /**
   * Await-timeout alarm (wall clock — deliberately not pause-aware, see spec §6.3).
   * Stale-timer guard: no-op unless still suspended at the SAME step index.
   */
  public function handle_timeout(int $process_id, int $step_index): void {
    // AS-action entry point like continue_scheduled: arm the resource
    // governor here. The FAIL branch below runs execute_compensation()
    // without passing through run(), so without this, time_exceeded() sees
    // null (governor off for the whole cascade) — or a stale started_at if
    // the runner instance is reused across AS callbacks.
    $this->started_at = time();

    // Same suspended-row mutation surface as resume_on_event — serialize with
    // it via the per-process lock. The find and ALL guards must run inside
    // the lock: a row read before the lock is won can be stale by the time we
    // hold it (e.g. the final event just resumed the process), which would
    // defeat the guards entirely.
    $this->with_process_lock($process_id, function () use ($process_id, $step_index) {
      $process = $this->repository->find($process_id);

      if ($process === null || $process->status() !== 'suspended') {
        return;
      }
      if ($process->current_step_index() !== $step_index) {
        return; // stale alarm — the saga already woke and moved on
      }

      $mechanism = $process->await_mechanism();
      if ($mechanism === null) {
        return;
      }

      // Sealed bracket for the wake itself. The outer with_process_lock
      // stays: the find + guards above must run inside the lock (stale-read
      // protection), and GET_LOCK is re-entrant per connection — the
      // bracket's inner acquisition is balanced by its own release.
      $this->with_process($process, function () use ($process, $mechanism) {
        if ($mechanism->on_timeout() === AwaitAll::TIMEOUT_PROCEED) {
          $process->advance_step();
          $this->resume_argument = $mechanism->resume_argument(null);
          $process->advance(status: 'running', payload: $process->payload());
          $this->repository->save($process);
          $this->run($process);
          $this->resume_argument = null;
          return;
        }

        // TIMEOUT_FAIL. Call execute_compensation() directly rather than run():
        // when the suspended step is the process's first step, begin_compensation()
        // leaves undo_index at -1 (nothing completed yet to undo), which is the
        // same value is_compensating() reports for "no compensation in progress" —
        // routing through run()'s is_compensating() gate would misfire back into
        // execute_forward(). execute_forward()'s own failure handler sidesteps
        // this the same way.
        $missing = method_exists($mechanism, 'missing') ? implode(', ', $mechanism->missing()) : '';
        $process->begin_compensation('Await timed out' . ($missing !== '' ? " — missing: $missing" : ''));
        $this->repository->save($process);
        $this->execute_compensation($process);
      });
    });
  }

  /**
   * Serialize accumulate/save per process via MySQL named lock.
   * Lock timeout → throw so ActionScheduler retries the delivery.
   */
  private function with_process_lock(int $process_id, callable $fn): void {
    global $wpdb;
    $name = 'ddd_process_' . $process_id;
    $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));

    if ((string) $acquired === '0') {
      throw new \RuntimeException("Could not acquire process lock $name — delivery will be retried.");
    }

    try {
      $fn();
    } finally {
      $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
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
    } catch (AwaitedEventNotRegistered $e) {
      // Config/wiring bug, not a business failure — don't mark the process
      // failed or announce ProcessFailed; propagate so the wiring gets fixed.
      throw $e;
    } catch (Throwable $e) {
      // Ensure we don't leave process in inconsistent state
      if ($process->status() !== 'failed') {
        $process->fail($e->getMessage());
        $this->repository->save($process);
        (new ProcessFailed($process, $e->getMessage()))->dispatch($this->config);
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

        $this->dispatch_commands($result, $process);

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
        $this->resume_argument = null; // Clear after first step post-resume
        $this->repository->save($process);

        // Check resources after each step
        if (!$process->is_steps_complete() && $this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (AwaitedEventNotRegistered $e) {
        // Config/wiring bug, not a business failure — don't compensate, fail fast.
        throw $e;
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

        $this->dispatch_commands($result, $process);

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

      } catch (AwaitedEventNotRegistered $e) {
        // Config/wiring bug, not a compensation failure — don't relabel, fail fast.
        throw $e;
      } catch (Throwable $e) {
        // Compensation failed - mark as failed and re-throw
        $process->fail('Compensation failed: ' . $e->getMessage());
        $this->repository->save($process);
        (new ProcessFailed($process, 'Compensation failed: ' . $e->getMessage()))->dispatch($this->config);
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

    // 2+ params: pass payload and the mechanism's resume argument
    return $step->invoke($process, $process->payload(), $this->resume_argument);
  }

  /**
   * Dispatch commands from a Result (fire-and-forget side effects).
   */
  private function dispatch_commands(Result $result, LongProcess $process): void {
    foreach ($result->commands as $command) {
      // Orchestration: the saga step IS the causer of these commands (dispatched
      // in-line, no event hop). Stamp the process as the causation; clear after
      // each so it can't bleed to a non-process command on the same worker.
      CorrelationContext::set_causation((string) $process->get_id(), 'long_process');
      try {
        $command->send();
      } finally {
        CorrelationContext::clear_causation();
      }
    }
  }

  /**
   * Suspend process waiting for an integration event.
   */
  private function suspend_for_event(LongProcess $process, Result $result): void {
    $mechanism = $result->await;

    if (!isset($this->registered_events[$mechanism->event_class()])) {
      throw new AwaitedEventNotRegistered($mechanism->event_class(), get_class($process));
    }

    $process->advance(
      status: 'suspended',
      payload: $result->payload,
      waiting_for: $mechanism->event_class(),
      await_mechanism: $mechanism,
    );
    $this->repository->save($process);

    if ($mechanism->timeout_seconds() > 0) {
      as_schedule_single_action(
        time() + $mechanism->timeout_seconds(),
        $this->config->hook('await_timeout'),
        ['process_id' => $process->get_id(), 'step_index' => $process->current_step_index()],
        $this->config->as_group('processes')
      );
    }
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

}
