<?php

namespace TangibleDDD\Application\Process;

use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Infrastructure\ProcessFailed;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessEntry;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\IStrandedScanner;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\Process\StrandedScanReport;
use TangibleDDD\Runtime\Scheduling\IWakeHandler;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Support\Log;
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
 * Every host interaction goes through a port (register 1.4, 3.6-3.8, 5.3):
 *
 *   IProcessStore          persistence, ignition dedup, version fence
 *   IProcessLock           the per-wake lock (wrapped in ReentrantProcessLock)
 *   IWakeupScheduler       durable timeouts, continuations and retries
 *   ISubscriptionRegistry  where register_event()/register_start() subscribe
 *   ITransactionBoundary   "save + intents" as one state change
 *   IClock                 absolute UTC due times
 *
 * Wave 3 contract (register section 8, 3.7, 3.8, 5.3; section 6 extraction
 * variants):
 *
 * - Re-read under the lock (C6, C7, E F1). Every wake (resume, timeout,
 *   continuation, retry) acquires the process lock, then re-reads the row
 *   and checks it is still in the state the wake expects (stale-safe:
 *   `suspended` + accepts the fact; `suspended` at the step index for a
 *   timeout; `scheduled` (+ step index) for a continuation). A stale wake is
 *   a quiet no-op.
 * - Await before dispatch (D3, C10, E F2). A suspending step's await, its
 *   timeout intent and the checkpoint commit in ONE transaction before the
 *   step's commands dispatch, so a fact those commands cause synchronously
 *   finds the process suspended. A non-suspending step does a fenced
 *   version touch (IProcessStore::touch) right before its commands
 *   dispatch; 0 rows (ConcurrentProcessModification) aborts the wake before
 *   any command runs. A resumed await cancels its timeout intent in the
 *   resuming save's transaction.
 * - One transaction per state change: process save + wakeup intents in one
 *   ITransactionBoundary::run (joined when the caller already has one open).
 * - ResumeRetry on contention (bug 1, extraction variant). Only a definite
 *   lock acquisition enters. When a direct wake entry (start, ignite's first
 *   step, continue_scheduled, handle_timeout: what Action Scheduler and
 *   in-band callers invoke) cannot take the lock, the runner writes a
 *   ResumeRetry intent (due after the wake backoff, 2 s) and THEN throws
 *   ProcessLockUnavailable, so the wake is re-queued, never lost and never
 *   run unlocked. Inside wake() (a drain item) it only throws: the drain
 *   re-queues its claimed intent. A fact resume that cannot lock throws
 *   too: the delivery invoker records a failed attempt for the resume
 *   subscriber and re-delivers the fact to it with backoff (the fact is the
 *   payload a retry needs).
 * - Ignition (X7, bug 2): #[StartsOn] facts go through
 *   IProcessStore::insertIgnited() (ignition_key = uuid5(event_id,
 *   process_class) under UNIQUE (process_class, ignition_key)); the loser
 *   returns without a step. Manual start() never dedups.
 * - Stranded scan (5.3 step 5): scanStranded() re-queues `scheduled` rows
 *   with no live intent and reports `running` ones.
 * - Start mode: StartMode::InBand (0.6, default) runs the first step in the
 *   request; StartMode::Deferred persists the process `scheduled` plus a
 *   Continue intent in the caller's transaction and takes no lock (sf
 *   default; legal inside a command because no step runs there).
 * - Step commands carry deterministic ids (DeterministicCommandId::forStep):
 *   a step re-run after a crash dispatches the same command ids.
 *
 * Constructor (R2): the 0.6.5 `(IDDDConfig, IProcessRepository)` call stays
 * valid; the repository became optional and the ports, the start mode and
 * the logger are optional trailing parameters. The config stays typed
 * IDDDConfig (consumers' runtime-compiled containers autowire this class).
 * A null port resolves from HostDefaults on first use (per-consumer ones
 * through HostDefaults::for(), the store adapting the given repository); a
 * port that cannot be resolved when it is needed throws \LogicException.
 *
 * Errors: lock failures surface as ProcessLockUnavailable (a 0.6
 * LockingException carrying the port's LockNotAcquired); a version-fence
 * failure (ConcurrentProcessModification) aborts the wake without failing
 * or compensating the process, because another holder owns it.
 */
final class ProcessRunner implements IProcessEntry, IWakeHandler, IStrandedScanner {
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

  /** >0 while inside wake(): contention propagates and the caller re-queues its intent. */
  private int $wake_depth = 0;

  /** Set by a continuation: the step it resumes at runs even if it is #[Async]. */
  private bool $skip_async_once = false;

  private ?IProcessStore $resolved_store = null;
  private ?IProcessLock $resolved_lock = null;
  private ?IWakeupScheduler $resolved_wakeups = null;
  private ?ISubscriptionRegistry $resolved_subscriptions = null;
  private ?IClock $resolved_clock = null;
  private bool $boundary_resolved = false;
  private ?ITransactionBoundary $resolved_boundary = null;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IProcessRepository $repository = null,
    private readonly ?IProcessLock $lock = null,
    private readonly ?IProcessStore $store = null,
    private readonly ?IWakeupScheduler $wakeups = null,
    private readonly ?ISubscriptionRegistry $subscriptions = null,
    private readonly ?ITransactionBoundary $boundary = null,
    private readonly ?IClock $clock = null,
    private readonly ?StartMode $start_mode = null,
    private readonly ?LoggerInterface $logger = null,
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
   * the fact drain. Inside a process it throws. Inside a command it throws
   * in the in-band mode (running the first step there would nest the step's
   * dispatched commands inside the caller's bus pass; handlers announce an
   * integration event instead and let #[StartsOn] react); in the deferred
   * mode no step runs, so a command may start a process atomically with its
   * own writes.
   *
   * In-band (default): insert, then run the first step under the process
   * lock. Deferred: insert as `scheduled` + a Continue intent in one
   * (joined) transaction; a worker's drain runs the first step.
   *
   * @throws ProcessStartedInsideCommand
   * @throws ProcessStartedInsideProcess
   * @throws ProcessLockUnavailable (in-band, after re-queueing a ResumeRetry)
   */
  public function start(LongProcess $process): void {
    $deferred = $this->start_mode() === StartMode::Deferred;
    $this->prepare_start($process, allow_inside_act: $deferred);

    if ($deferred) {
      $process->advance(status: 'scheduled', payload: $process->payload());
      $this->atomically(function () use ($process): void {
        $id = $this->store()->insert($process);
        $this->versions[$id] = 1;
        $this->wakeups()->schedule(WakeupIntent::continuation(
          $this->config->prefix(), $id, $process->current_step_index(), $this->clock()->now()
        ));
      });
      return;
    }

    $this->atomically(fn () => $this->versions[$this->store()->insert($process)] = 1);
    $this->run_started($process);
  }

  /**
   * IProcessEntry: the #[StartsOn] ignition door. Exactly one ignited process
   * per (process_class, event_id), however many deliveries or workers:
   * IProcessStore::insertIgnited() is the gate (X7). The loser returns
   * quietly without running a step. The first step runs after the insert,
   * under the per-process lock like every other wake; if that lock is
   * contended, a ResumeRetry runs it later (a redelivery of the fact finds
   * the ignition already done). Manual start() calls never pass through here
   * and are never deduped.
   *
   * An empty $eventId (an id-less legacy payload) cannot be deduped: the
   * process starts as 0.6 did, sourced 'event', without the ignition gate.
   *
   * @param class-string<LongProcess> $processClass
   * @throws ProcessLockUnavailable when the ignition or process lock is not acquired
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

    // One INSERT against UNIQUE (process_class, ignition_key) on SQL hosts;
    // a store that gates with a named lock (LegacyProcessStore, wp) may throw
    // LockNotAcquired, and nothing was persisted then.
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
   * IWakeHandler: run one due wakeup (a drain item). Stale = no-op;
   * contention throws without scheduling a ResumeRetry (the caller
   * re-queues the claimed intent).
   */
  public function wake(WakeupIntent $intent): void {
    if ($intent->kind === WakeKind::Deliver) {
      throw new \LogicException("ProcessRunner does not run Deliver wakes ({$intent->idempotencyKey})");
    }
    if ($intent->processId === null) {
      throw new \InvalidArgumentException("Wakeup {$intent->idempotencyKey} has no process id");
    }

    $this->wake_depth++;
    try {
      match ($intent->kind) {
        WakeKind::Continue => $this->continue_scheduled($intent->processId, $intent->stepIndex),
        WakeKind::Timeout => $this->handle_timeout($intent->processId, (int) $intent->stepIndex),
        WakeKind::ResumeRetry => $this->resume_retry($intent),
      };
    } finally {
      $this->wake_depth--;
    }
  }

  /**
   * IStrandedScanner (register 5.3 step 5): `scheduled` rows with no live
   * intent get a fresh Continue intent (stale-safe); `running` rows are
   * reported only.
   */
  public function scanStranded(\DateTimeImmutable $now): StrandedScanReport {
    $requeued = [];
    $reported = [];
    foreach ($this->store()->findStranded($now) as $stranded) {
      if ($stranded->status !== 'scheduled') {
        $reported[] = $stranded;
        continue;
      }
      $intent = WakeupIntent::continuation(
        $this->config->prefix(), $stranded->processId, $stranded->stepIndex, $now,
        'stranded-' . $now->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis'),
      );
      $this->atomically(fn () => $this->wakeups()->schedule($intent));
      $requeued[] = $stranded->processId;
    }
    return new StrandedScanReport($requeued, $reported);
  }

  /**
   * Continue a scheduled process (the Continue wake: Action Scheduler's
   * `{prefix}_process_continue` on WordPress). Stale-safe: under the lock
   * the row must still be `scheduled` and, when $step_index is given, at
   * that step.
   */
  public function continue_scheduled(int $process_id, ?int $step_index = null): void {
    $this->with_process_lock(
      $process_id,
      function () use ($process_id, $step_index): void {
        $process = $this->find($process_id);
        if ($process === null || $process->status() !== 'scheduled') {
          return; // deleted, finished, or already continued
        }
        if ($step_index !== null && $process->current_step_index() !== $step_index) {
          return; // a continuation for a step already passed
        }

        $this->in_scope($process, function () use ($process): void {
          $process->advance(status: 'running', payload: $process->payload());
          $this->persist($process);
          $this->skip_async_once = true;
          $this->run($process);
        });
      },
      fn () => $this->retry_intent($process_id, $step_index, 'scheduled', 0),
    );
  }

  /**
   * Resume suspended processes when an integration event fires. Each
   * candidate is pre-filtered without the lock, then re-read and re-checked
   * under it. Contention propagates (the delivery retries this subscriber).
   */
  public function resume_on_event(IIntegrationEvent $event): void {
    $event_class = get_class($event);

    foreach ($this->store()->findWaitingFor($event_class) as $process_id) {
      try {
        $candidate = $this->store()->find($process_id);
      } catch (QuarantinedProcess) {
        continue; // undecodable row: quarantined by the store, the worker continues
      }
      if ($candidate === null || !($candidate->await_mechanism()?->accepts($event) ?? false)) {
        continue;
      }

      $resumed = false;
      $this->with_process_lock($process_id, function () use ($process_id, $event, &$resumed): void {
        $process = $this->find($process_id); // re-read under the lock (C6, C7)
        if ($process === null || $process->status() !== 'suspended') {
          return;
        }
        $mechanism = $process->await_mechanism();
        if ($mechanism === null || !$mechanism->accepts($event)) {
          return;
        }
        $resumed = true;

        $this->in_scope($process, function () use ($process, $event, $mechanism): void {
          $updated = $mechanism->accumulate($event);

          if (!$updated->is_satisfied()) {
            // Partial arrival: persist the tally, stay suspended (the alarm stays).
            $process->update_await($updated);
            $this->persist($process);
            return;
          }

          $suspended_at = $process->current_step_index();
          $process->advance_step();
          $this->resume_argument = $updated->resume_argument($event);
          $process->advance(status: 'running', payload: $process->payload());
          $this->persist($process, null, $mechanism->timeout_seconds() > 0
            ? WakeupIntent::timeoutKey((int) $process->get_id(), $suspended_at)
            : null);

          try {
            $this->run($process);
          } finally {
            $this->resume_argument = null;
          }
        });
      });

      // Only resume first accepting process per event (key sets are disjoint by construction).
      if ($resumed) {
        return;
      }
    }
  }

  /**
   * Await-timeout alarm (wall clock — deliberately not pause-aware, see spec §6.3).
   * Stale-timer guard: no-op unless still suspended at the SAME step index,
   * checked on the row re-read under the lock.
   */
  public function handle_timeout(int $process_id, int $step_index): void {
    // Wake entry point like continue_scheduled: arm the resource governor
    // here. The FAIL branch below runs execute_compensation() without
    // passing through run(), so without this, time_exceeded() sees null.
    $this->started_at = time();

    $this->with_process_lock(
      $process_id,
      function () use ($process_id, $step_index): void {
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

        $this->in_scope($process, function () use ($process, $mechanism): void {
          if ($mechanism->on_timeout() === AwaitAll::TIMEOUT_PROCEED) {
            $process->advance_step();
            $this->resume_argument = $mechanism->resume_argument(null);
            $process->advance(status: 'running', payload: $process->payload());
            $this->persist($process);
            try {
              $this->run($process);
            } finally {
              $this->resume_argument = null;
            }
            return;
          }

          // TIMEOUT_FAIL. Call execute_compensation() directly rather than run():
          // when the suspended step is the process's first step, begin_compensation()
          // leaves undo_index at -1 (nothing completed yet to undo), which is the
          // same value is_compensating() reports for "no compensation in progress" —
          // routing through run()'s is_compensating() gate would misfire back into
          // execute_forward().
          $missing = method_exists($mechanism, 'missing') ? implode(', ', $mechanism->missing()) : '';
          $process->advance(status: 'running', payload: $process->payload());
          $process->begin_compensation('Await timed out' . ($missing !== '' ? " — missing: $missing" : ''));
          $this->persist($process);
          $this->execute_compensation($process);
        });
      },
      fn () => $this->retry_intent($process_id, $step_index, 'suspended', 0),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Start, brackets, persistence
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * First half of start(): guards, ignition absorb, source and lifecycle.
   * Persists nothing and runs no step.
   */
  private function prepare_start(LongProcess $process, bool $allow_inside_act = false): void {
    // Guards read the facade: "what am I inside?" is the ambient cause's kind.
    $cause = Correlation::peek()?->cause;

    if ($cause?->kind === Kind::Act && !$allow_inside_act) {
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

  /** Second half of an in-band start or ignition: the first step, under the lock. */
  private function run_started(LongProcess $process): void {
    $id = (int) $process->get_id();
    $this->with_process_lock(
      $id,
      fn () => $this->in_scope($process, fn () => $this->run($process)),
      fn () => $this->retry_intent($id, $process->current_step_index(), 'running', $this->version_of($id)),
    );
  }

  /** The ResumeRetry wake: repeat the wake its expected status names. */
  private function resume_retry(WakeupIntent $intent): void {
    $id = (int) $intent->processId;
    match ($intent->expectedStatus) {
      'scheduled' => $this->continue_scheduled($id, $intent->stepIndex),
      'suspended' => $intent->stepIndex === null ? null : $this->handle_timeout($id, $intent->stepIndex),
      'running' => $this->resume_started($id, $intent->stepIndex, $intent->retryVersion()),
      default => null,
    };
  }

  /**
   * Run the first step of a process whose in-band start could not take the
   * lock. Stale-safe: the row must still be `running` at the same step and
   * the same version (a holder that ran it bumped the version).
   */
  private function resume_started(int $process_id, ?int $step_index, int $version): void {
    $this->with_process_lock($process_id, function () use ($process_id, $step_index, $version): void {
      $process = $this->find($process_id);
      if ($process === null || $process->status() !== 'running') {
        return;
      }
      if (($step_index !== null && $process->current_step_index() !== $step_index) || $this->version_of($process_id) !== $version) {
        return;
      }
      $this->in_scope($process, fn () => $this->run($process));
    });
  }

  /**
   * The sealed bracket's scope half — every saga wake executes inside it:
   * the correlation scope (dispatched commands stay in the saga's trace;
   * scope-exit is worker hygiene — one worker runs many wakes) and the
   * process frame (what makes command-inside-process-scope legible to the
   * guards). The lock half is with_process_lock(), taken BEFORE the row is
   * re-read.
   */
  private function in_scope(LongProcess $process, callable $work): void {
    $ctx = new TraceContext($process->correlation_id());
    Correlation::within($ctx->for_trajectory((string) $process->get_id(), get_class($process)), $work);
  }

  /**
   * Run $fn holding the per-process lock (IProcessLock; tenant '' in core).
   * Only a definite acquisition enters; otherwise nothing ran, no release is
   * issued for a lock never held, a direct entry ($retry given, outside
   * wake()) re-queues itself as a ResumeRetry intent, and
   * ProcessLockUnavailable propagates.
   *
   * @param null|\Closure(): WakeupIntent $retry
   */
  private function with_process_lock(int $process_id, callable $fn, ?\Closure $retry = null): void {
    $lock = $this->lock();
    try {
      $handle = $lock->acquire(new LockKey($this->config->prefix(), '', $process_id), self::LOCK_TIMEOUT_SECONDS);
    } catch (LockNotAcquired $e) {
      if ($retry !== null && $this->wake_depth === 0) {
        $this->requeue($retry(), $e);
      }
      throw new ProcessLockUnavailable($e->getMessage(), 0, $e);
    }

    try {
      $fn();
    } finally {
      $lock->release($handle);
    }
  }

  private function retry_intent(int $process_id, ?int $step_index, string $expected_status, int $version): WakeupIntent {
    $now = $this->clock()->now();
    return WakeupIntent::resumeRetry(
      $this->config->prefix(), $process_id, $step_index, $expected_status, $version,
      $now->modify('+' . WakeRetryPolicy::backoffSeconds(1) . ' seconds'),
      $now->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis.u'),
    );
  }

  /** Write the ResumeRetry; a scheduler that cannot take it is logged, never silent. */
  private function requeue(WakeupIntent $intent, LockNotAcquired $cause): void {
    try {
      $this->atomically(fn () => $this->wakeups()->schedule($intent));
    } catch (Throwable $e) {
      Log::write($this->logger, sprintf(
        '[%s process] wake of process #%d could not take its lock (%s) and could not be re-queued as %s: %s',
        $this->config->prefix(), (int) $intent->processId, $cause->getMessage(), $intent->idempotencyKey, $e->getMessage()
      ), 'error');
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

  /** A version-fenced save of the process's current state, with its intents, in one transaction. */
  private function persist(LongProcess $process, ?WakeupIntent $schedule = null, ?string $cancel = null): void {
    $this->atomically(function () use ($process, $schedule, $cancel): void {
      $id = (int) $process->get_id();
      $this->versions[$id] = $this->store()->save($process, $this->version_of($id));
      if ($cancel !== null) {
        $this->wakeups()->cancel($cancel);
      }
      if ($schedule !== null) {
        $this->wakeups()->schedule($schedule);
      }
    });
  }

  /**
   * The fenced version touch right before a step's commands dispatch
   * (register 3.7, CR-5): if another holder changed the row since this
   * runner read it, ConcurrentProcessModification aborts the wake before any
   * command runs.
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
    $skip_async = $this->skip_async_once;
    $this->skip_async_once = false;

    while (!$process->is_steps_complete()) {
      $step_name = $process->current_step_name();
      if ($step_name === null) {
        break;
      }

      $method = $reflection->getMethod($step_name);

      // #[Async] forces a reschedule before execution — once: the
      // continuation that resumes at this step runs it.
      if ($this->has_async_attribute($method) && !$skip_async) {
        $this->schedule_continuation($process);
        return;
      }
      $skip_async = false;

      try {
        $result = $this->execute_step($process, $method);

        if (!($result instanceof Result)) {
          throw new \RuntimeException(
            "Step {$step_name} must return a Result, got " . get_debug_type($result)
          );
        }

        if ($result->should_suspend()) {
          // F2: the await (and its timeout intent) commits before the
          // step's commands dispatch.
          $this->suspend_then_dispatch($process, $result, (string) $process->current_step_index(), false);
          return;
        }

        $this->fence($process);
        $this->dispatch_commands($result, $process, (string) $process->current_step_index(), false);

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
        $this->enter_compensation($process, $e->getMessage());
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
    $skip_async = $this->skip_async_once;
    $this->skip_async_once = false;

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

      // #[Async] on compensation method (once; see execute_forward)
      if ($this->has_async_attribute($method) && !$skip_async) {
        $this->schedule_continuation($process, 'undo-' . $step_name);
        return;
      }
      $skip_async = false;

      try {
        $checkpoint = $process->checkpoint_for($step_name);
        $result = $method->invoke($process, $cause, $checkpoint);

        if ($result->should_suspend()) {
          $this->suspend_then_dispatch($process, $result, $step_name, true);
          return;
        }

        $this->fence($process);
        $this->dispatch_commands($result, $process, $step_name, true);

        $process->advance(status: 'running', payload: $result->payload);
        $process->advance_compensation();
        $this->persist($process);

        if ($this->resources_exceeded()) {
          $this->schedule_continuation($process, 'undo-' . ($process->current_undo_step() ?? 'end'));
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

  /**
   * begin_compensation as one state change. A process that had already
   * persisted its suspension (a step's command failed after the await
   * committed) leaves `suspended` first, so no fact can resume it mid-
   * compensation, and its timeout intent is cancelled with the save.
   */
  private function enter_compensation(LongProcess $process, string $message): void {
    $cancel = null;
    if ($process->status() === 'suspended') {
      $mechanism = $process->await_mechanism();
      if ($mechanism !== null && $mechanism->timeout_seconds() > 0) {
        $cancel = WakeupIntent::timeoutKey((int) $process->get_id(), $process->current_step_index());
      }
      $process->advance(status: 'running', payload: $process->payload());
    }
    $process->begin_compensation($message);
    $this->persist($process, null, $cancel);
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
   * Dispatch commands from a Result (fire-and-forget side effects), each
   * with its deterministic step command id.
   */
  private function dispatch_commands(Result $result, LongProcess $process, string $step, bool $compensation): void {
    // No arming loop (0.3): these sends run inside the wake bracket's
    // trajectory scope — the act bracket reads the ambient cause, so every
    // command parents on the saga by scope semantics.
    $ordinal = 0;
    foreach ($result->commands as $command) {
      $id = DeterministicCommandId::forStep($this->config->prefix(), (int) $process->get_id(), $step, $ordinal++, $compensation);
      DeterministicCommandId::within($id, static fn () => $command->send());
    }
  }

  /**
   * F2: persist the suspension (await + timeout intent, one transaction),
   * then dispatch the step's commands. If a command fails, the caller
   * compensates, unless a fact delivered during the dispatch already moved
   * the process on: this copy is then stale and must not be written over
   * the newer state (ConcurrentProcessModification, not a business failure).
   */
  private function suspend_then_dispatch(LongProcess $process, Result $result, string $step, bool $compensation): void {
    $this->suspend_for_event($process, $result);

    $id = (int) $process->get_id();
    $suspended_version = $this->versions[$id] ?? null;
    try {
      $this->dispatch_commands($result, $process, $step, $compensation);
    } catch (Throwable $e) {
      if ($suspended_version !== null && ($this->store()->versionOf($id) ?? $suspended_version) !== $suspended_version) {
        throw new ConcurrentProcessModification(
          "Process #$id moved on while its step's commands dispatched; the failed dispatch does not compensate over the newer state: " . $e->getMessage(),
          0,
          $e
        );
      }
      throw $e;
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
   * durable Continue intent due now, in one state change. Compensation
   * continuations carry a discriminator, because the step index does not
   * move while compensating.
   */
  private function schedule_continuation(LongProcess $process, ?string $discriminator = null): void {
    $process->advance(status: 'scheduled', payload: $process->payload());

    $this->persist($process, WakeupIntent::continuation(
      $this->config->prefix(),
      (int) $process->get_id(),
      $process->current_step_index(),
      $this->clock()->now(),
      $discriminator,
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

  /** The constructor's mode, else a host-wide HostDefaults::provide(StartMode::class, StartMode::X), else InBand. */
  private function start_mode(): StartMode {
    if ($this->start_mode !== null) {
      return $this->start_mode;
    }
    $host = HostDefaults::get(StartMode::class);
    return $host instanceof StartMode ? $host : StartMode::InBand;
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
