<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * Optional HostFixture seam for the wave-3 process and lock scenarios
 * (register section 4 `lock.*`, `process.*` and the `.process` variants;
 * change request CR-W3CP-1). A separate interface, so a fixture written
 * against the ratified HostFixture keeps compiling; a fixture without it
 * has those scenarios skipped with the request id.
 *
 * Everything binds to the fixture's per-test schema:
 *
 * - the runner of worker(1) uses HostFixture::processLock(),
 *   HostFixture::boundary(), HostFixture::clock() and
 *   HostFixture::subscriptions(), plus processStore() and wakeups();
 * - deliveries on any worker use HostFixture::ledger();
 * - FixtureCommand-style step commands (Fixtures\Process\StepCommand) need
 *   no bus: they record themselves in ProcessJournal (and, once the host
 *   called ProcessJournal::bind(), in HostFixture::scenarioRows()).
 *
 * The start mode is the host's own (in-band on mem, pdo and wp; deferred on
 * sf, CR-W3C-1): scenarios start processes through a helper that drains
 * once when the first step was deferred.
 */
interface ProcessHost {

  /**
   * Declare the processes the scenario uses, on EVERY worker of the
   * fixture (now and those built later): register_start() for each pair,
   * register_event() for each awaited fact class.
   *
   * @param list<array{0: class-string<LongProcess>, 1: class-string<IIntegrationEvent>}> $starts
   * @param list<class-string<IIntegrationEvent>> $awaits
   */
  public function wireProcesses(array $starts, array $awaits): void;

  /** Worker $n (1 = the fixture's connection; see ProcessWorker). */
  public function worker(int $n = 1): ProcessWorker;

  // ── ports ────────────────────────────────────────────────────────────────

  public function processStore(): IProcessStore;

  public function wakeups(): IWakeupScheduler;

  /** The host's merged operator view (register 3.10). */
  public function operatorView(): IOperatorView;

  /** The consumer prefix the runners lock and key intents under (LockKey::$consumer, WakeupIntent::$consumer). */
  public function processConsumer(): string;

  /** The key worker(1)'s runner locks process $processId under (tenant '' outside wp multisite). */
  public function processLockKey(int $processId): LockKey;

  // ── read-back (no lock, no transaction) ──────────────────────────────────

  public function processRow(int $id): ?ProcessRow;

  /**
   * @param class-string<LongProcess>|null $processClass
   * @return list<int> ids in insertion order
   */
  public function processIds(?string $processClass = null): array;

  /** @return list<WakeupIntent> intents not yet completed or cancelled, in scheduling order */
  public function pendingWakeups(): array;

  // ── lock faults (register 3.7) ───────────────────────────────────────────

  /** A second connection takes process $processId's lock and keeps it (pdo/wp: GET_LOCK on another session; sf: pg_try_advisory_lock). */
  public function holdProcessLockElsewhere(int $processId): void;

  public function releaseProcessLockElsewhere(int $processId): void;

  /** The next backend acquire answers NULL / false / a query error (one-shot). */
  public function failNextProcessLockAcquire(string $reason): void;

  /** Successful BACKEND acquisitions so far (all workers): re-entrant acquisitions are not counted. */
  public function processLockAcquisitions(): int;

  /**
   * Run $fn once, right before worker(1)'s next BACKEND lock acquire (an
   * interleaving point: worker 1 has read the row and is about to lock).
   * $fn typically makes worker(2) act; it runs with no transaction open on
   * worker 1's connection.
   */
  public function beforeNextProcessLockAcquire(callable $fn): void;

  // ── wake transport fault ─────────────────────────────────────────────────

  /**
   * Make the host's wake transport unavailable for the NEXT hand-off of an
   * intent, wherever the host hands intents over: at schedule time (wp, the
   * Action Scheduler projection), at relay time (sf, the `ddd_wakeups`
   * Messenger send) or when a drain executes a claimed intent (pdo jobs,
   * mem). The intent row must survive it (register 5.3 step 3).
   */
  public function failNextWakeHandoff(string $reason): void;
}
