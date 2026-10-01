# Wave 3: sf-conformance3 change requests

Author: sf-conformance3 (wave 3, rounds 2-3). Branch `wave3/sf-conformance3`. Owned paths: `packages/ddd-symfony/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (section 4, section 8 wave 3), [wave2-notes.md](wave2-notes.md), [wave3-notes.md](wave3-notes.md), [wave3-core-change-requests.md](wave3-core-change-requests.md) (W3C-R4), [wave3-conformance-process-change-requests.md](wave3-conformance-process-change-requests.md) (W3CP-R1, W3CP-R2).

No ratified interface was changed. Nothing here needs another owner's code: core, conformance and packaging are untouched, and `packages/ddd-symfony/composer.json` is unchanged.

## What this round did

| Task | Where |
|---|---|
| W3C-R4: start mode | `Runtime\Factory::processRunner()` passes core `start_mode`: `StartMode::Deferred` by default, `StartMode::InBand` when `tangible_ddd.process.inband_start: true` (CR-W3C-1). New `Factory::startMode(bool)`. The reflective `startModeParameter()` probe and its warning are gone. |
| W3C-R4: relay | `Runtime\Relay::runOnce($limit)` calls core `process_batch($limit)` with the real store and transport, and builds `RelayReport` from `ProcessingResult` (CR sfc-1, sfc-3, sfc-4). `@internal` `RelayOutcomes`, `RelayTransportView` and `LeaseLostOnAccept` are deleted. Nothing on sf throws `LeaseLostOnAccept` any more, because core rolls a shared-connection submission back on a 0-row accept. |
| W3C-R4: logger | `@internal` `Runtime\RuntimeLog` (the closure arm of CR-SP-1) is deleted. Loggers go to core constructors as `?LoggerInterface`. |
| 38 sf ids on Postgres 16 | `tests/Conformance/SfHostFixture.php` implements `ProcessHost`, `FreshProcesses`, `WebRequests`, `RelayRace`, `StatementErrors`, `AuditSinkFaults` and `RecordsSignals`. Six new scenario classes cover the wave-3 cases. `SfCatalogueTest` pins the 15 wave-2 ids and the 23 wave-3 ids. |
| W3CP-R1 (`delivery.delayed-once`) | The fixture records host clock minus wall clock at each `ddd_facts` send. `transported()` reports `available_at` plus that offset, so the due time is on the host clock. The `sfc-2` skip is removed. |
| wave-2 carry-over `audit.sink-fails` | A fault-injecting audit sink. Signals come from the bundle's `SymfonySignalDispatcher` and are read back as `DddSignal` events. |
| CR sf-7 on Postgres | `DbalTransactionBoundary` (see behaviour change 2). |

## SF3-1: `ProcessWakeupHandler::__invoke()` returns the intent outcome (additive)

- **What.** The handler used to return `void`. It now returns one of the new constants `COMPLETED`, `RETRIED`, `EXHAUSTED` or `LEASE_LOST`, which Messenger carries as the `HandledStamp` result. `LEASE_LOST` means the complete, retry or exhaust write matched 0 rows. Behaviour is unchanged.
- **Why.** `ProcessWorker::drainOnce()` must return a core `DrainReport` with `wakesCompleted`, `wakesRetried`, `wakesExhausted` and `wakesLeaseLost`. `process.stale-wakeup` and `lock.contention` assert on those lists. On sf the wakes run through `ddd_wakeups` and this handler. Without a result, the outcome could only be inferred from row state, and a completed row is deleted.
- **Compatibility.** A caller that ignored the old `void` keeps working.

## SF3-2: `RelayReport::$result` and `RelayReport::of()` (additive)

An optional trailing constructor argument `?ProcessingResult $result = null` holds the core step's own result, and the static `of(ProcessingResult, list<string> $deadLetteredAtClaim)` builds a report from it. A drain's `DrainReport::$relay` is that result.

## Behaviour changes (no signature change)

1. **The sf runner's `start()` is deferred by default.** The process is persisted as `scheduled` with a `Continue` intent in the caller's transaction, no process lock is taken, and the first step runs in a worker. Since wave 2 the bundle documented this, but the runner had stayed in-band, with a warning, because the option name the factory looked for did not match core's `start_mode`. Code that relied on `start()` running the first step must set `tangible_ddd.process.inband_start: true` and use a direct connection.
2. **`DbalTransactionBoundary`, CR sf-7.** A work can catch a statement error and then throw from a later statement of the same aborted transaction, with SQLSTATE `25P02` in the chain (for example the command's outbox append). That throw now surfaces as `TransactionFailed`, with the work's exception as previous. The pre-COMMIT probe already did this when the work returned normally. Every other work exception is still rethrown unchanged. Found by the sf-7 case of `cmd.commit-failure`, which ran on sf for the first time this round: it reported `OutboxWriteFailed` where the scenario requires `TransactionFailed`.
3. **Relay report fields.** A lease lost on accept is reported in `lost` by core, as before. The report's lists now come from core in the order core produces them.

## Removed `@internal` classes

`Runtime\RelayOutcomes`, `Runtime\RelayTransportView`, `Runtime\LeaseLostOnAccept` and `Runtime\RuntimeLog`. All were marked `@internal`, and nothing outside `packages/ddd-symfony` referenced them (checked with grep over the repo and `/Users/titustc/tgbl/txp-slices`, read-only).

## How the sf fixture maps the seams (for reviewers)

| Seam | sf mapping |
|---|---|
| `worker(1)` | The fixture's one DBAL connection: boundary, outbox, ledger, process store, intents, advisory-lock session, and the `ddd_facts` and `ddd_wakeups` Doctrine transports. |
| `worker(n > 1)` | The same composition on a new DBAL connection over the same schema. |
| `drainOnce()` | `Relay::runOnce()`, then the due `ddd_facts` consumed by a Messenger `Worker`, then `WakeupRelay::runOnce()` (stranded scan, due intents projected), then the due `ddd_wakeups` consumed through `ProcessWakeupHandler`. `DddRuntimeReset` runs after each message. The result is reported as a `DrainReport`. |
| `holdProcessLockElsewhere` | `pg_advisory_lock` on a separate session. |
| `failNextProcessLockAcquire` | The next `pg_try_advisory_lock(?)` statement on any fixture connection is replaced by one the server rejects (22P02, carrying the reason). Implemented in `ScenarioSchemaMiddleware` and `StatementFaults`. |
| `failNextWakeHandoff` | The next send to `ddd_wakeups`, which `WakeupRelay` retries later. |
| `FreshProcesses` | `tests/Conformance/bin/fresh-process.php`, a separate `php` process that composes the same classes over the test schema (`SfHostFixture::attach()`) on the host clock's current instant. A kill is `SIGKILL` of that process. |
| `WebRequests` | During `inWebRequest()`, every process-lock acquire goes to a `PostgresAdvisoryProcessLock` over a pooled DSN with `PoolerPolicy::Refuse`. `bootInBandStartOnPooledDsn()` boots the real `TestKernel` variant `inband_pooled`. |
| `RelayRace` | The competitor's statements, made through the very store objects the scenario captured, go to a second session (`RaceableConnection`, a DBAL `wrapperClass`). They stay committed when the relay's transaction rolls back. |
| `StatementErrors` | A duplicate key inside the open transaction, which Postgres answers with `25P02` for everything after it. |

## Open items (not change requests)

- **Fresh processes are not `bin/console`.** The fresh `php` process composes the bundle's classes by hand, as the fixture does. It does not boot a kernel, because the kernel cannot be pointed at a per-test schema without a DBAL middleware the bundle does not configure. The kernel wiring is covered by `tests/Kernel/*`.
- **Fresh-process clock.** The fresh process uses a `FrozenClock` at the parent's exact instant instead of `EnvOffsetClock` / `DDD_CLOCK_OFFSET`. A drifting offset clock made the 900 s stranded threshold of `process.crash-mid-step` depend on wall-clock timing.
- **No bundle `IOperatorView` service yet.** The fixture builds the core `PortOperatorView` over `DbalOutboxAdministration` and `DbalProcessStore`. The bundle has no `tangible_ddd.operator_view` service. The register lists the merged sf D9 view (with the Messenger failure transport) under wave 4.
- **The sf stranded scan is `WakeupRelay`'s, not core `ProcessRunner::scanStranded()`.** Both requeue `scheduled` rows and report `running` ones. The sf re-queue key is `continue:{id}:{step}` with no discriminator. That is safe on sf because completed intent rows are deleted. Switching to the core scanner can be done in wave 4 with the repair commands.
