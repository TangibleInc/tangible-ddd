<?php

namespace TangibleDDD\Application\Process;

use ReflectionClass;
use ReflectionMethod;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Infrastructure\ProcessFailed;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessEntry;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\SystemClock;
use Throwable;

/**
 * Executes long-running processes step by step.
 *
 * Responsibilities:
 * - Register event types for process resume, and #[StartsOn] ignitions
 * - Discover steps via reflection (methods in declaration order)
 * - Execute steps and dispatch returned commands
 * - Suspend on an await mechanism and persist state
 * - Resume when a matching integration event is delivered
 * - Reschedule (a durable Continue intent) when resources are exhausted or
 *   on an #[Async] step
 * - Run compensations in reverse on failure
 *
 * All entry points (start, continue, resume, timeout) flow through the same
 * run() path.
 *
 * Core form (register 1.4, section 8 wave 2; ruling #70 option b). Every
 * host interaction goes through a wave-1 port:
 *
 *   IProcessStore        persistence, ignition dedup, version fence
 *   IProcessLock         the per-wake lock (wrapped in ReentrantProcessLock)
 *   IWakeupScheduler     await timeouts and continuations, written in the
 *                        same transaction as the state change (5.3)
 *   ISubscriptionRegistry  where register_event()/register_start() subscribe
 *   ITransactionBoundary   wraps "save + intents" (joins an open one)
 *   IClock               absolute UTC due times
 *
 * Constructor (R2): the 0.6.5 `(IDDDConfig, IProcessRepository)` call stays
 * valid; the identity is widened to IConsumerIdentity, the repository became
 * optional, and the six ports are optional trailing parameters. A null port
 * resolves from HostDefaults on first use (per-consumer ones through
 * HostDefaults::for(), the store adapting the given repository). ddd-wp
 * fills HostDefaults at init, so 0.6 compiled containers get WordPress
 * behaviour; elsewhere pass the ports. A port that cannot be resolved when
 * it is needed throws \LogicException naming it.
 *
 * Errors: lock failures surface as ProcessLockUnavailable (a 0.6
 * LockingException carrying the port's LockNotAcquired); a version-fence
 * failure (ConcurrentProcessModification) aborts the wake without failing
 * or compensating the process, because another holder owns it.
 *
 * Wave 2 keeps the 0.6 step order (commands dispatch, then the await
 * persists); await-before-dispatch, re-read under the lock and ResumeRetry
 * on contention are wave 3.
 */
final class ProcessRunner implements IProcessEntry {
  use RescheduleAware;

  /** Seconds a wake waits for its process lock (0.6: GET_LOCK(…, 5)). */
  private const LOCK_TIMEOUT_SECONDS = 5.0;

  /** @var array<string, bool> Tracks which events have resume subscriptions */
  private array $registered_events = [];

  /** @var array<string, array<string, bool>> process class → event class → ignition subscribed */
  private array $registered_starts = [];

  /** @var mixed Transient - resume_argument() output from the mechanism that woke the process */
  private mixed $resume_argument = null;

  /** @var array<int, int> process id → last version this runner read or wrote */
  private array $versions = [];

  private ?IProcessStore $resolved_store = null;
  private ?IProcessLock $resolved_lock = null;
  private ?IWakeupScheduler $resolved_wakeups = null;
  private ?ISubscriptionRegistry $resolved_subscriptions = null;
  private ?IClock $resolved_clock = null;
  private bool $boundary_resolved = false;
  private ?ITransactionBoundary $resolved_boundary = null;

  public function __construct(
    private readonly IConsumerIdentity $config,
    private readonly ?IProcessRepository $repository = null,
    private readonly ?IProcessLock $lock = null,
    private readonly ?IProcessStore $store = null,
    private readonly ?IWakeupScheduler $wakeups = null,
    private readonly ?ISubscriptionRegistry $subscriptions = null,
    private readonly ?ITransactionBoundary $boundary = null,
    private readonly ?IClock $clock = null,
  ) {}

  // ─────────────────────────────────────────────────────────────────────────
  // Public API
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Register an event type for process resume.
   *
   * Call this for each event type that processes may await. The resume
   * subscription goes to the ISubscriptionRegistry at Subscriber::RESUME
   * (99, after listeners and ignitions). An event no registered consumer
   * owns is skipped with a note (its plugin is inactive), as in 0.6.
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

    if (IntegrationHookName::resolve($event_class) === null) {
      IntegrationHookName::note_absent($event_class, 'process resume');
      return $this;
    }

    $this->registered_events[$event_class] = true;

    $this->subscriptions()->add(new Subscriber(
      $this->config->prefix() . '/resume:' . $event_class,
      Subscriber::RESUME,
      $event_class,
      function (IIntegrationEvent $event): void {
        $this->resume_on_event($event);
      },
    ));

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
   * Priority 50 (Subscriber::IGNITION): after plain listeners (10), before
   * resumes (99) — so an event may ignite saga B before it wakes saga A,
   * deterministically, and a saga that both StartsOn and Awaits one event
   * class has its igniting instance consumed by ignition (the await sees
   * only later arrivals).
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

    if (IntegrationHookName::resolve($event_class) === null) {
      IntegrationHookName::note_absent($event_class, 'process ignition');
      return $this;
    }

    $this->registered_starts[$process_class][$event_class] = true;

    $this->subscriptions()->add(new Subscriber(
      $this->config->prefix() . '/ignition:' . $process_class . '@' . $event_class,
      Subscriber::IGNITION,
      $event_class,
      function (IIntegrationEvent $event, string $event_id = '') use ($process_class): void {
        $this->ignite($process_class, $event, $event_id);
      },
    ));

    return $this;
  }

  /**
   * Start a new process — the EDGE door.
   *
   * Legal from flat contexts only: REST controllers, CLI, hook closures,
   * the fact drain (where #[StartsOn] ignition calls it). Inside a command
   * pass it throws: running the first step there would nest the step's
   * dispatched commands inside the caller's bus pass. Handlers announce an
   * integration event instead and let #[StartsOn] react.
   *
   * The first step runs in-band, immediately — same rule as every wake:
   * steps execute wherever ignition or waking legally happens; only awaits
   * and timeouts create hops.
   *
   * @throws ProcessStartedInsideCommand
   * @throws ProcessStartedInsideProcess
   */
  public function start(LongProcess $process): void {
    $this->prepare_start($process);
    $this->atomically(fn () => $this->versions[$this->store()->insert($process)] = 1);
    $this->run_started($process);
  }

  /**
   * IProcessEntry: the #[StartsOn] ignition door. Exactly one ignited process
   * per (process_class, event_id), however many deliveries or workers:
   * IProcessStore::insertIgnited() is the gate (X7; on WordPress the wave-1
   * has_ignition re-check under a named lock). The loser returns quietly
   * without running a step. The first step runs after the insert, under the
   * per-process lock like every other wake. Manual start() calls never pass
   * through here and are never deduped.
   *
   * An empty $eventId (an id-less legacy payload) cannot be deduped: the
   * process starts as 0.6 did, sourced 'event', without the ignition gate.
   *
   * @param class-string<LongProcess> $processClass
   * @throws ProcessLockUnavailable when the ignition lock is not acquired
   */
  public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void {
    $process = $processClass::from_event($event);
    if ($process === null) {
      return; // not my business — the policy declined
    }

    if ($eventId === '') {
      $process->mark_source('event');
      $this->start($process);
      return;
    }

    $process->mark_ignited_by($eventId);
    $process->mark_source('event');
    $this->prepare_start($process);

    // Not wrapped in atomically(): the gate must be visible to the next
    // worker the moment it decides. On SQL hosts it is one INSERT against
    // UNIQUE (process_class, ignition_key); on WordPress (wave 2) it is a
    // re-check + insert under a named lock, and a transaction committing
    // after that lock's release would reopen the bug-2 race.
    $result = $this->locked(fn () => $this->store()->insertIgnited($process, $processClass, $eventId));
    if ($result === IgnitionResult::AlreadyIgnited) {
      return; // redelivery / concurrent worker: this fact already ignited its saga
    }

    $this->versions[(int) $process->get_id()] = $this->store()->versionOf((int) $process->get_id()) ?? 1;
    $this->run_started($process);
  }

  /** IProcessEntry: wake the processes suspended on this fact. */
  public function resume(IIntegrationEvent $event): void {
    $this->resume_on_event($event);
  }

  /**
   * Continue a scheduled process (the Continue wake: Action Scheduler's
   * `{prefix}_process_continue` on WordPress).
   */
  public function continue_scheduled(int $process_id): void {
    $process = $this->find($process_id);

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
      $this->persist($process);

      $this->run($process);
    });
  }

  /**
   * Resume suspended processes when an integration event fires.
   */
  public function resume_on_event(IIntegrationEvent $event): void {
    $event_class = get_class($event);

    foreach ($this->store()->findWaitingFor($event_class) as $process_id) {
      $process = $this->find($process_id);
      if ($process === null) {
        continue;
      }
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
          $this->persist($process);
          return;
        }

        $process->advance_step();
        $this->resume_argument = $updated->resume_argument($event);
        $process->advance(status: 'running', payload: $process->payload());
        $this->persist($process);

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
    // Wake entry point like continue_scheduled: arm the resource governor
    // here. The FAIL branch below runs execute_compensation() without
    // passing through run(), so without this, time_exceeded() sees null
    // (governor off for the whole cascade) — or a stale started_at if the
    // runner instance is reused across wakes.
    $this->started_at = time();

    // Same suspended-row mutation surface as resume_on_event — serialize with
    // it via the per-process lock. The find and ALL guards must run inside
    // the lock: a row read before the lock is won can be stale by the time we
    // hold it (e.g. the final event just resumed the process), which would
    // defeat the guards entirely.
    $this->with_process_lock($process_id, function () use ($process_id, $step_index) {
      $process = $this->find($process_id);

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
      // protection); the lock is re-entrant (ReentrantProcessLock), so the
      // backend sees one acquisition for the whole wake.
      $this->with_process($process, function () use ($process, $mechanism) {
        if ($mechanism->on_timeout() === AwaitAll::TIMEOUT_PROCEED) {
          $process->advance_step();
          $this->resume_argument = $mechanism->resume_argument(null);
          $process->advance(status: 'running', payload: $process->payload());
          $this->persist($process);
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
        $this->persist($process);
        $this->execute_compensation($process);
      });
    });
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Start, brackets, persistence
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * First half of start(): guards, ignition absorb, source and lifecycle.
   * Persists nothing and runs no step.
   */
  private function prepare_start(LongProcess $process): void {
    // Guards read the facade: "what am I inside?" is the ambient cause's kind.
    $cause = Correlation::peek()?->cause;

    if ($cause?->kind === Kind::Act) {
      throw new ProcessStartedInsideCommand(get_class($process), $cause->label ?? $cause->id);
    }
    if ($cause?->kind === Kind::Trajectory) {
      throw new ProcessStartedInsideProcess(get_class($process), $cause->id);
    }

    // The absorb: a manual ->start() inside a drain is a legal-but-
    // dispreferred spelling of event ignition — the ambient fact IS the
    // igniter, so record the truth instead of a false cold root.
    // (#[StartsOn] stays the better door: it adds dedup and discovery.)
    if ($process->ignited_by_event_id() === null && $cause?->kind === Kind::Fact) {
      $process->mark_ignited_by($cause->id);
      $process->mark_source('event');
    }

    if ($process->source() === null) {
      // WP-CLI runs under the CLI SAPI, so this is the 0.6 rule.
      $process->mark_source(PHP_SAPI === 'cli' ? 'cli' : 'web');
    }

    // Story inheritance: an ignition drain's scope wins, else a fresh story —
    // minted WITHOUT touching the ambient (current() would persist a mint
    // into the worker and bleed into whatever runs next).
    $correlation = Correlation::peek()?->correlation_id
      ?? \TangibleDDD\Domain\Shared\Uuid::v4();

    $steps = $this->create_process_steps($process);
    $process->initialize_lifecycle($correlation, $steps);
  }

  /** Second half of start(): the first step, in-band, inside the sealed bracket. */
  private function run_started(LongProcess $process): void {
    $this->with_process($process, fn () => $this->run($process));
  }

  /**
   * The sealed bracket — every saga wake, any lane (start, continuation,
   * resume, timeout), executes inside it. Owns, in order: the per-process
   * lock (serializes concurrent wakes of one saga), the correlation scope
   * (dispatched commands stay in the saga's trace; scope-exit is worker
   * hygiene — one worker runs many wakes), and the process frame (what makes
   * command-inside-process-scope legible to the guards).
   *
   * Deliberately not a pluggable pipeline: the bracket set is fixed and
   * framework-owned. If a real extension case arrives (ops pause, per-
   * consumer wake policy), this body is where it graduates.
   */
  private function with_process(LongProcess $process, callable $work): void {
    $this->with_process_lock((int) $process->get_id(), function () use ($process, $work) {
      // ONE scope value: the saga's story + itself as the ambient cause
      // (0.3 lane 3). Cross-story wakes (a saga woken by a foreign story's
      // fact) switch here and restore on exit.
      $ctx = new TraceContext($process->correlation_id());

      Correlation::within(
        $ctx->for_trajectory((string) $process->get_id(), get_class($process)),
        $work
      );
    });
  }

  /**
   * Run $fn holding the per-process lock (IProcessLock; tenant '' in core).
   * Only a definite acquisition enters; otherwise ProcessLockUnavailable
   * propagates, nothing ran, and no release is issued for a lock never held.
   */
  private function with_process_lock(int $process_id, callable $fn): void {
    $lock = $this->lock();
    $handle = $this->locked(fn () => $lock->acquire(
      new LockKey($this->config->prefix(), '', $process_id),
      self::LOCK_TIMEOUT_SECONDS
    ));

    try {
      $fn();
    } finally {
      $lock->release($handle);
    }
  }

  /**
   * Runs $fn, turning the port's LockNotAcquired into the 0.6-catchable
   * ProcessLockUnavailable (a LockingException).
   *
   * @template T
   * @param callable():T $fn
   * @return T
   */
  private function locked(callable $fn): mixed {
    try {
      return $fn();
    } catch (LockNotAcquired $e) {
      throw new ProcessLockUnavailable($e->getMessage(), 0, $e);
    }
  }

  /**
   * "save + intents" as one state change (register 5.3): inside the
   * boundary's transaction, or inside the caller's when one is already open
   * (joining it rather than nesting, which the Reject policy forbids). With
   * no boundary at all the work runs directly.
   *
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function atomically(callable $work): mixed {
    $boundary = $this->boundary();
    if ($boundary === null || $boundary->isActive()) {
      return $work();
    }
    return $boundary->run($work);
  }

  /** A version-fenced save of the process's current state. */
  private function persist(LongProcess $process, ?WakeupIntent $intent = null): void {
    $this->atomically(function () use ($process, $intent): void {
      $id = (int) $process->get_id();
      $this->versions[$id] = $this->store()->save($process, $this->version_of($id));
      if ($intent !== null) {
        $this->wakeups()->schedule($intent);
      }
    });
  }

  /**
   * The fenced version touch before a step's commands dispatch (register
   * 3.7, CR-5): if another holder changed the row since this runner read it,
   * ConcurrentProcessModification aborts the wake before any command runs.
   */
  private function fence(LongProcess $process): void {
    $id = (int) $process->get_id();
    $this->versions[$id] = $this->atomically(fn () => $this->store()->touch($id, $this->version_of($id)));
  }

  private function find(int $process_id): ?LongProcess {
    $process = $this->store()->find($process_id);
    if ($process !== null) {
      $this->versions[$process_id] = $this->store()->versionOf($process_id) ?? 1;
    }
    return $process;
  }

  private function version_of(int $process_id): int {
    return $this->versions[$process_id] ??= ($this->store()->versionOf($process_id) ?? 1);
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
    } catch (AwaitedEventNotRegistered|ConcurrentProcessModification $e) {
      // A wiring bug, or another holder owns the row: not a business
      // failure — don't mark the process failed or announce ProcessFailed.
      throw $e;
    } catch (Throwable $e) {
      // Ensure we don't leave process in inconsistent state
      if ($process->status() !== 'failed') {
        $process->fail($e->getMessage());
        $this->persist($process);
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

      $this->fence($process);

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
        $this->persist($process);

        // Check resources after each step
        if (!$process->is_steps_complete() && $this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (AwaitedEventNotRegistered|ConcurrentProcessModification $e) {
        // Wiring bug / lost ownership, not a business failure — don't compensate.
        throw $e;
      } catch (Throwable $e) {
        // Enter compensation mode
        $process->begin_compensation($e->getMessage());
        $this->persist($process);
        $this->execute_compensation($process);
        return;
      }
    }

    // All steps completed
    $process->complete();
    $this->persist($process);
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
        $this->persist($process);
        continue;
      }

      $comp_method_name = $process->compensation_for($step_name);

      // No compensation registered - skip
      if ($comp_method_name === null) {
        $process->advance_compensation();
        $this->persist($process);
        continue;
      }

      $method = $reflection->getMethod($comp_method_name);

      // #[Async] on compensation method
      if ($this->has_async_attribute($method)) {
        $this->schedule_continuation($process);
        return;
      }

      $this->fence($process);

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
        $this->persist($process);

        if ($this->resources_exceeded()) {
          $this->schedule_continuation($process);
          return;
        }

      } catch (AwaitedEventNotRegistered|ConcurrentProcessModification $e) {
        // Wiring bug / lost ownership, not a compensation failure — don't relabel.
        throw $e;
      } catch (Throwable $e) {
        // Compensation failed - mark as failed and re-throw
        $process->fail('Compensation failed: ' . $e->getMessage());
        $this->persist($process);
        (new ProcessFailed($process, 'Compensation failed: ' . $e->getMessage()))->dispatch($this->config);
        throw $e;
      }
    }

    // All compensations complete
    $process->finish_compensation();
    $this->persist($process);
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
    // No arming loop (0.3): these sends run inside the wake bracket's
    // trajectory scope — the act bracket reads the ambient cause, so every
    // command parents on the saga by scope semantics.
    foreach ($result->commands as $command) {
      $command->send();
    }
  }

  /**
   * Suspend process waiting for an integration event; a timeout becomes a
   * durable Timeout intent in the same state change.
   */
  private function suspend_for_event(LongProcess $process, Result $result): void {
    $mechanism = $result->await;

    if (!$this->awaits_are_subscribed($mechanism->event_class())) {
      throw new AwaitedEventNotRegistered($mechanism->event_class(), get_class($process));
    }

    $process->advance(
      status: 'suspended',
      payload: $result->payload,
      waiting_for: $mechanism->event_class(),
      await_mechanism: $mechanism,
    );

    $intent = $mechanism->timeout_seconds() > 0
      ? WakeupIntent::timeout(
          $this->config->prefix(),
          (int) $process->get_id(),
          $process->current_step_index(),
          $this->clock()->now()->modify('+' . $mechanism->timeout_seconds() . ' seconds'),
        )
      : null;

    $this->persist($process, $intent);
  }

  /**
   * Schedule the process to continue later: status `scheduled` plus a
   * durable Continue intent due now, in one state change.
   */
  private function schedule_continuation(LongProcess $process): void {
    $process->advance(status: 'scheduled', payload: $process->payload());

    $this->persist($process, WakeupIntent::continuation(
      $this->config->prefix(),
      (int) $process->get_id(),
      $process->current_step_index(),
      $this->clock()->now(),
    ));
  }

  /**
   * Is someone resuming processes on this fact? This runner's own
   * register_event(), or a SubscriptionRegistrar `resume:<class>` subscriber
   * in the same registry.
   */
  private function awaits_are_subscribed(string $event_class): bool {
    if (isset($this->registered_events[$event_class])) {
      return true;
    }
    $registry = $this->subscriptions ?? HostDefaults::get(ISubscriptionRegistry::class);
    if ($registry === null) {
      return false;
    }
    foreach ($registry->for($event_class) as $subscriber) {
      if ($subscriber->id === 'resume:' . $event_class) {
        return true;
      }
    }
    return false;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Port resolution (R2: explicit argument, else HostDefaults, else fail loudly)
  // ─────────────────────────────────────────────────────────────────────────

  private function store(): IProcessStore {
    return $this->resolved_store ??= $this->store
      ?? HostDefaults::for(IProcessStore::class, $this->config, $this->repository)
      ?? throw new \LogicException(
        'ProcessRunner has no ' . IProcessStore::class . ': pass one, or run on a host that provides one '
        . '(ddd-wp adapts the IProcessRepository at init).'
      );
  }

  private function lock(): IProcessLock {
    if ($this->resolved_lock === null) {
      $lock = $this->lock ?? HostDefaults::get(IProcessLock::class) ?? throw new \LogicException(
        'ProcessRunner has no ' . IProcessLock::class . ': pass one, or run on a host that provides one.'
      );
      $this->resolved_lock = $lock instanceof ReentrantProcessLock ? $lock : new ReentrantProcessLock($lock);
    }
    return $this->resolved_lock;
  }

  private function wakeups(): IWakeupScheduler {
    return $this->resolved_wakeups ??= $this->wakeups
      ?? HostDefaults::for(IWakeupScheduler::class, $this->config)
      ?? throw new \LogicException(
        'ProcessRunner has no ' . IWakeupScheduler::class . ' for timeouts and continuations: pass one, or run on a host that provides one.'
      );
  }

  private function subscriptions(): ISubscriptionRegistry {
    return $this->resolved_subscriptions ??= $this->subscriptions
      ?? HostDefaults::get(ISubscriptionRegistry::class)
      ?? throw new \LogicException(
        'ProcessRunner has no ' . ISubscriptionRegistry::class . ' to register resumes and ignitions in: pass one, or run on a host that provides one.'
      );
  }

  private function boundary(): ?ITransactionBoundary {
    if (!$this->boundary_resolved) {
      $this->resolved_boundary = $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);
      $this->boundary_resolved = true;
    }
    return $this->resolved_boundary;
  }

  private function clock(): IClock {
    return $this->resolved_clock ??= $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
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
