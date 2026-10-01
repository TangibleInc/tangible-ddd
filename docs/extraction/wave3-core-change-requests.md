# Wave 3: core change requests

Author: core (wave 3). Branch `wave3/core`. Owned paths: `packages/ddd-core/src/**` except `Defaults/Pdo/**`, `packages/ddd-core/tests/Unit/**`, `tests/Unit/Process/**` (this wave only), `examples/plain-php/**`. Binding inputs: [contract-register.md](contract-register.md) (section 8 wave 3 core, 3.4-3.10, 5.1-5.3, 6), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave1-notes.md](wave1-notes.md), [wave2-notes.md](wave2-notes.md).

Every item below is additive (new interface, new class, optional trailing parameter, new optional method argument) or a behaviour change the register asks for. No ratified interface lost or changed a method. Section "Behaviour changes" lists what callers can observe.

## What this round did

| Task | Where |
|---|---|
| CR-SP-1 cleanup: runtime loggers are `?LoggerInterface` only | `Runtime/Support/Log`, `ReentrantProcessLock`, `IntegrationDelivery`, `InMemoryTransactionBoundary`, `LoggingSignalDispatcher` |
| CR sfc-1: lost lease on accept rolls back a shared submission | `Infra/Services/OutboxProcessor` + `LeaseLostOnAccept` (`@internal`) |
| CR sfc-3: `process_batch(?int $limit = null)` | `OutboxProcessor` |
| CR sfc-4: per-event outcomes | `ProcessingResult` trailing lists `claimed`, `accepted`, `retried`, `deadLettered`, `leaseLost` (both forms) |
| CR sfc-5: `retry()` of a dlq row removes its DLQ entries | `IOutboxAdministration` docblock (contract for every host), `InMemoryOutboxStore` |
| `LegacyOutboxStore` (R3, unfenced, logged) | `Runtime/Outbox/LegacyOutboxStore` |
| `LegacyProcessStore` (R3, named-lock ignition, unfenced, logged) | `Runtime/Process/LegacyProcessStore`, `Runtime/Lock/INamedLock`, `Testing/InMemoryNamedLock` |
| ProcessRunner behind the ports (re-read under the lock, F2, one-transaction state changes, ResumeRetry, fenced touch, ignition, stranded scan, start modes) | `Application/Process/ProcessRunner`, `StartMode` |
| `Drain::runOnce(maxItems, maxSeconds)` | `Runtime/Drain`, `Runtime/DrainReport`, `Runtime/Delivery/IDeliveryWorker` |
| `IOperatorView` (D9 shape, layer labels) | `Runtime/Ops/*` |

Tests: `packages/ddd-core/tests/Unit/Process/ProcessRunnerWave3Test.php`, `LegacyProcessStoreTest.php`, `tests/Unit/Runtime/{DrainTest,OperatorViewTest,LegacyOutboxStoreTest}.php`, additions to `OutboxProcessorCoreTest`, `InMemoryOutboxStoreTest`, `PsrLoggingTest`. All on the mem doubles, no WordPress stub.

## CR-W3C-1: ProcessRunner start mode and logger (optional trailing parameters)

- **What.** `ProcessRunner::__construct()` gains `?StartMode $start_mode = null, ?LoggerInterface $logger = null` after `?IClock $clock`. New enum `Application\Process\StartMode { InBand = 'inband'; Deferred = 'deferred' }`. Resolution: the constructor value, else `HostDefaults::get(StartMode::class)` (an enum case is an object, so `HostDefaults::provide(StartMode::class, StartMode::Deferred)` works), else `InBand`.
- **Deferred** (register 3.8 "sf start", 5.2): `start()` runs the guards, marks the process `scheduled`, and in one transaction (joined when the caller has one open) inserts it and schedules `WakeupIntent::continuation(prefix, id, 0, now)`. No lock is taken and no step runs. The first step runs when a drain/worker wakes the intent.
- **Guard relaxation.** In the deferred mode `start()` is legal inside a command (ambient cause `Kind::Act`): the 0.6 reason for `ProcessStartedInsideCommand` (the first step's commands would nest in the caller's bus pass) does not apply when no step runs, and sf needs the start to commit atomically with the command's own writes. `ProcessStartedInsideProcess` is unchanged; in-band starts inside a command still throw.
- **Why.** Task: "start() supports 'persist + Continue intent' mode (hosts choose; sf default) besides in-band". The logger carries the "could not re-queue" error (CR-W3C-3).
- **Compatibility.** R2 holds: the 0.6.5 `(IDDDConfig, IProcessRepository)` call and the wave-2 eight-argument call are unchanged.

## CR-W3C-2: `INamedLock`; legacy bridge constructors

- **What.** New interface `Runtime\Lock\INamedLock { acquire(string $name, float $timeoutSeconds): void /* throws LockNotAcquired */; release(string $name): void /* never throws */ }` and `Testing\InMemoryNamedLock` (`holdElsewhere()`, `failNextAcquire()`).
- `LegacyProcessStore(IProcessRepository $repository, IConsumerIdentity $consumer, ?INamedLock $lock = null, ?LoggerInterface $logger = null)`. Ignition: `ddd_ign_` + md5(prefix|class|event_id) around `has_ignition()` + insert (the hotfix approach, register 3.8). The lock resolves from the argument, else `HostDefaults::get(INamedLock::class)`; with neither, `insertIgnited()` throws `\LogicException` (an unguarded check-then-insert would reopen bug 2). Saves and touches are not fenced; the missing fence is logged once per instance (warning). `findStranded()` returns `[]` (the 0.6 interface cannot enumerate by status and age).
- `LegacyOutboxStore(IOutboxRepository $repository, ?LoggerInterface $logger = null, ?string $workerId = null)`. `claim()` = `release_stale_locks($lease)` + `fetch_pending($limit, $workerId)`; `accept()`/`retryLater()`/`deadLetter()` = `mark_completed()`/`mark_failed()`/`move_to_dlq()`, always `true` (unfenced, logged once). `due_at` is the entry's absolute `scheduled_at` read as UTC, so a 0.6 row with `delay_seconds > 0` is not delayed twice (bug 3, extraction variant). `append()` throws `OutboxWriteFailed`: `IOutboxRepository::write()` takes an event and mints its own `event_id`, so a port record cannot be appended with its identity; such consumers keep publishing through the 0.6 bus form. `LegacyOutboxStore::record(OutboxEntry)` is public (the mapping is reusable).
- **Why.** Register 3.4 / 3.8 name the bridges but not their collaborators; the named lock has no process id to key an `IProcessLock` on.
- **Compatibility.** New types only.

## CR-W3C-3: `IWakeHandler`, `IStrandedScanner`, ResumeRetry keys, `continue_scheduled(?int $step_index)`

- **What.**
  - `Runtime\Scheduling\IWakeHandler { wake(WakeupIntent $intent): void }`. `ProcessRunner` implements it for Continue, Timeout and ResumeRetry; Deliver throws `\LogicException`. Stale = no-op; contention throws and the caller (Drain) re-queues the claimed intent; inside `wake()` the runner never writes a ResumeRetry of its own.
  - `Runtime\Process\IStrandedScanner { scanStranded(\DateTimeImmutable $now): StrandedScanReport }` and `StrandedScanReport { requeued: list<int>, reported: list<StrandedProcess> }`. `ProcessRunner` implements it: `scheduled` rows → a fresh Continue intent (one transaction each), `running` rows → reported only (register 5.3 step 5).
  - `ProcessRunner::continue_scheduled(int $process_id, ?int $step_index = null)`: the new optional argument is the intent's step index for the stale check.
  - `WakeupIntent` gains static helpers and one accessor: `timeoutKey(int, int)`, `continuation(..., ?string $discriminator = null)` (key `continue:{id}:{step}:{discriminator}` when given; compensation continuations use `undo-{step}`, the stranded re-queue uses `stranded-{YmdHis}`, so a retained completed row never swallows a later continuation of the same step index), `resumeRetry(consumer, processId, ?stepIndex, expectedStatus, version, dueAt, nonce)` (key `resume_retry:{id}:{step|-}:{expected_status}:{version}:{nonce}`), `retryVersion()`.
  - `Runtime\Scheduling\WakeRetryPolicy` (5.1 wake budget: `BUDGET = 10`, `backoffSeconds(n) = min(300, 2 × 2^(n-1))`).
- **ResumeRetry semantics** (bug 1 extraction variant, register 3.7 "On LockNotAcquired the runner schedules a ResumeRetry"). A direct wake entry that cannot lock writes a ResumeRetry intent due after `backoffSeconds(1)` (2 s) and then throws `ProcessLockUnavailable` (so the caller still sees `LockNotAcquired` as the previous, as `lock.contention` expects). The intent's `expectedStatus` names the wake to repeat: `scheduled` → continuation, `suspended` + `stepIndex` → the await timeout, `running` + `stepIndex` + key version → the first step of an in-band start or ignition (no-op if the row version moved, i.e. someone ran it). Direct entries: `start()` (in-band), the first step after `ignite()`, `continue_scheduled()`, `handle_timeout()`.
- **Fact resume.** A fact resume that cannot lock does NOT write a ResumeRetry: `WakeupIntent` carries no payload, and the fact is what the resume needs. It throws, so `IntegrationDelivery` records a failed attempt for the resume subscriber only and the delivery runner re-delivers the fact to it with the handler backoff (register 3.7 "re-queues the delivery"). The ledger budget (5) bounds it; exhaustion is in the operator view, layer `delivery`.
- **If the scheduler refuses the ResumeRetry** (the wave-2 transitional wp scheduler throws for that kind), the runner logs an error naming the wake and still throws the lock failure: never silent, but the re-queue is lost on that host until wp's v8 scheduler takes the kind (request W3C-R1).
- **Why.** Drain needs one call per claimed intent, and the ResumeRetry wake needs a typed shape.
- **Compatibility.** New types; one optional argument; static helpers on a final class. Keys of the existing helpers (`timeout:{id}:{step}`, `continue:{id}:{step}`) are unchanged.

## CR-W3C-4: `IDeliveryWorker`; `Drain` and `DrainReport` shape

- **What.** `Runtime\Delivery\IDeliveryWorker { runDue(\DateTimeImmutable $now, int $limit): int }` (the delivery stage; pdo implements it over `{prefix}_ddd_jobs` deliver rows; hosts whose transport delivers by itself need none).
- `Runtime\Drain::__construct(?OutboxProcessor $relay = null, ?IWakeupScheduler $wakeups = null, ?IWakeHandler $processWakes = null, ?IDeliveryWorker $delivery = null, ?IStrandedScanner $stranded = null, ?IClock $clock = null, ?LoggerInterface $logger = null, ?IWakeHandler $deliverWakes = null, int $wakeLeaseSeconds = 60)`; every stage optional. `runOnce(int $maxItems = 200, int $maxSeconds = 50): DrainReport` (frozen signature, register 3.6). Order: relay step (`process_batch(remaining)`), deliveries, due wakeups (Deliver kinds to `$deliverWakes`), stranded scan. One item budget (relay claims + deliveries + wakes) and one wall-time budget (hrtime, not IClock), checked before each stage and each wake. A failing wake is `retryLater()`'d with `WakeRetryPolicy`; at 10 attempts it is reported exhausted and logged as an error but kept, retried at the cap. `RuntimeReset::betweenMessages()` after the relay batch, the delivery batch and each wake; leaks are cleaned, logged, reported. A stage that throws is logged and listed; the pass continues.
- `DrainReport { relay: ?ProcessingResult, delivered, wakesCompleted, wakesRetried, wakesExhausted, wakesLeaseLost, stranded: ?StrandedScanReport, items, stoppedBy: idle|max_items|max_seconds, leaks, errors }`.
- **Why.** The register sketches only `runOnce()`; the stages need collaborators. `DurableRuntime::drain()` (pdo) composes one.
- **Compatibility.** New types.

## CR-W3C-5: operator view types

- **What.** `Runtime\Ops\Layer` (the six register values; `label()`, `description()`), `OperatorItem(Layer $layer, string $consumer, string $key, int $attempts, ?int $budget, ?string $lastError, ?\DateTimeImmutable $firstSeen, list<string> $repairActions)` with `toArray()` (snake_case D9 keys plus `layer_label`, times ISO 8601 UTC: the arrays pdo's view returns), `IOperatorView::list(?Layer $layer = null, int $limit = 100)` (register 3.10, unchanged), new `IOperatorItemSource::items(?Layer $layer, int $limit): list<OperatorItem>`, and `PortOperatorView(IConsumerIdentity, ?IOutboxAdministration, ?IProcessStore, ?IClock, list<IOperatorItemSource>)`: relay layer from `deadLetters()` (budget = record `max_attempts`; repairs `retry`, `replay`, `discard`), process layer from `findStranded()` `running` rows (repairs `resume_stranded`, `fail_stranded`), the rest from sources. Ordered by layer, then first seen; `$limit` caps the merged list.
- Mem: `InMemoryDeliveryLedger` gains an optional constructor `(string $consumer = '', int $budget = IntegrationDelivery::DEFAULT_BUDGET)` and implements `IOperatorItemSource` (layer `delivery`, key `subscriber@event_id`, repair `redeliver`); `InMemoryWakeupScheduler` implements it (layer `wakeup`, intents with attempts > 0, budget 10, repair `retry_wake`).
- **Why.** The register fixes the field list but not how a host composes layers.
- **Compatibility.** New types; the ledger's constructor arguments are optional.

## CR-W3C-6: deterministic step command ids (D13, landed early)

- **What.** `DeterministicCommandId::forStep(string $consumer, int $processId, int|string $step, int $ordinal, bool $compensation = false): string` and `PROCESS_NAMESPACE`. The runner sends each step command inside `DeterministicCommandId::within(forStep(prefix, id, step_index, k))`; compensation commands use the compensated step's name and phase `undo`. 32 hex, the same shape as the fact-cause ids.
- **Why.** Register 3.8 ("inside a process step: uuid5(process_id, step_index)") and `process.crash-mid-step` (pdo, wp, sf in wave 3: "resume re-runs the step with the same deterministic command id"). Register section 8 lists D13's deterministic ids under wave 4; the pdo/wp/sf wave-3 scenario needs them now. The process id is an integer, so the name-based form is `uuid5(uuid5(NS, "{consumer}:{process_id}"), "{phase}:{step}:{ordinal}")`.
- **Compatibility.** Additive API. Behaviour: step commands' `command_id` (audit) is now deterministic and repeats when a step re-runs, exactly as listener commands inside a fact cause already do.

## Behaviour changes (no signature change)

1. **Re-read under the lock.** Resume pre-filters candidates without the lock, then re-reads and re-checks under it; continuation and timeout read only under the lock. A row moved on before the lock is handled on its new state (a wave-2 test that expected `ConcurrentProcessModification` there now expects the wake to work on the re-read row).
2. **F2.** A suspending step persists its await + timeout intent before its commands dispatch. A fact delivered synchronously inside the dispatch resumes the process (nested wake on the re-entrant lock). If a command then fails and the process did not move on, it leaves `suspended` (await withdrawn, timeout intent cancelled) and compensates; if a nested wake already moved it on, the failure surfaces as `ConcurrentProcessModification` and the stale copy is not written.
3. **Fence position.** The fenced `touch()` now runs right before a non-suspending step's commands dispatch (wave 2: before the step method ran). A suspending step's fenced save plays that role.
4. **Timeout cancel.** A satisfied await cancels its `timeout:{id}:{step}` intent in the resuming save's transaction (wp's transitional scheduler maps this to `as_unschedule_action`).
5. **`continue_scheduled()`** continues only rows still `scheduled` (and at `$step_index` when given). 0.6 continued anything not `completed`/`failed`, including a `running` row, which is exactly the stranded case register 5.3 forbids re-running automatically.
6. **`#[Async]`** reschedules once: the continuation that resumes at the async step runs it. 0.6 and wave 2 rescheduled it on every continuation, forever.
7. **`handle_timeout()`** takes the lock once (wave 2: twice, re-entrantly).
8. **sfc-1.** A shared-connection relay no longer commits the transport insert when `accept()` matches 0 rows; the row is in `leaseLost`, not an attempt.
9. **sfc-5.** `IOutboxAdministration::retry()` of a `dlq` row deletes its DLQ entries (contract for every host).
10. **CR-SP-1.** Passing a `\Closure` as a runtime logger is now a `TypeError`. Conformance and ddd-symfony already pass PSR-3 loggers (`RuntimeLog::argument()` prefers `LoggerInterface`).

## Requests to other owners

- **W3C-R1 (wp, v8 scheduler):** accept `WakeKind::ResumeRetry` intents (project them to a DDD-owned AS hook whose callback calls `ProcessRunner::wake()` with the stored intent; the key carries the version for `running` retries). Until then the wave-2 `ActionSchedulerWakeupScheduler` throws for the kind and the runner logs "could not be re-queued" (visible in `run.sh wp-integration` output).
- **W3C-R2 (wp):** Continue keys may have a fourth segment (CR-W3C-3); the transitional `cancel()` ignores non-three-part keys, which is harmless because the runner never cancels continuations.
- **W3C-R3 (wp):** `{prefix}_process_continue` now no-ops unless the row is `scheduled` (behaviour 5). `tests/Unit/Process/**` returns to wp at the end of this wave; `ProcessLockAcquisitionTest` still pins the hotfix half (no re-queue) because the transitional scheduler cannot take ResumeRetry; update it with W3C-R1.
- **W3C-R4 (symfony):** `RelayOutcomes::accept()` can stop throwing `LeaseLostOnAccept` (sfc-1 is in core); `Relay` can call `process_batch($limit)` and read the `ProcessingResult` lists instead of copying `OutboxConfig`; `RuntimeLog`'s closure arm can go. Wire `StartMode::Deferred` as the bundle default (constructor or `HostDefaults::provide(StartMode::class, …)`), `ddd.process.inband_start: true` → `StartMode::InBand`.
- **W3C-R5 (pdo-default):** `IDeliveryWorker` over the deliver jobs; `DurableRuntime::drain()` returns a core `Drain` (relay, deliveries, the runner as wake handler and stranded scanner); `PdoOperatorView` can be `PortOperatorView` plus `IOperatorItemSource`s for the jobs and ledger tables.
- **W3C-R6 (conformance):** the mem process scenarios can drive `ProcessRunner::wake()` / `Drain::runOnce()`; `lock.contention`'s "later succeeds" is the ResumeRetry (direct entries) or the drain's `retryLater` (claimed intents).

## Not done here (left open)

- Repair commands (`ResumeStrandedProcess`, `FailStrandedProcess`, retry-wake, redeliver) are only named in `repairActions`; no handlers yet.
- `LegacyProcessStore::findStranded()` is empty and `LegacyOutboxStore` cannot append (see CR-W3C-2).
- The root `tests/Unit/Process/**` suites were kept as they are (they run the core runner on the transitional wp adapters and stay green); the mem-double coverage of the wave-3 runner lives in `packages/ddd-core/tests/Unit/Process/`.
