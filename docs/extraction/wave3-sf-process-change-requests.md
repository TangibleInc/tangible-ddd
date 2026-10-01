# Wave 3: sf-process change requests

Author: sf-process (wave 3). Branch `wave3/sf-process`. Owned paths: `packages/ddd-symfony/**` and this file. Every item is additive or a request to another owner; no ratified interface was changed. Binding inputs: [contract-register.md](contract-register.md) (3.6-3.8, 5.2, 5.3, 6, 8), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave1-notes.md](wave1-notes.md) (CR-3..CR-5), [wave2-notes.md](wave2-notes.md).

## What this round did

| Register item | sf class / file |
|---|---|
| 5.2 Postgres lock | `Lock\PostgresAdvisoryProcessLock`: session `pg_try_advisory_lock(LockKey::postgresKey())`, polled every 50-200 ms (jittered) to the deadline; only a definite `true` enters; a query error is `LockNotAcquired` with the driver error as previous; `release()` / `forceReleaseAll()` never throw. Wrapped in the core `ReentrantProcessLock` and registered with `RuntimeReset::guardLock` (`Runtime\Factory::processLock`). |
| 5.2 pooler | `Persistence\ConnectionTopology` (host contains `-pooler`, or port 6432, on the primary or a replica), `PoolerPolicy` (`warn` / `refuse`). The lock checks at its first `acquire()`, not at construction, because web requests build the runner on a pooled connection legitimately. `inband_start: true` on a pooled DSN is refused at boot (`HostDefaultsInstaller`). |
| 3.8, X7 | `Persistence\DbalProcessStore`: `insertIgnited` = `INSERT ... ON CONFLICT (process_class, ignition_key) DO NOTHING RETURNING id`; `save` / `touch` fenced on `version`; quarantine (`failed` + `quarantine_reason`, version + 1); `process_waits` rewritten in the same transaction as every save; `findWaitingFor` matches class, parents and interfaces; `findStranded` = running/scheduled, `updated_at` past the threshold, no live `ddd_wakeups` row. Row JSON shapes are the WordPress ones (`ProcessRowCodec`). |
| 3.6, 5.3 | `Persistence\DbalWakeupScheduler` (`ddd_wakeups`), `Runtime\Wakeup\WakeupRelay` (leases due intents and sends one `Messenger\ProcessWakeupMessage` per lease, no `DelayStamp`; throttled stranded scan re-queues `scheduled` rows and reports `running` ones), `Messenger\ProcessWakeupHandler` (wakes through `IProcessWakeTarget`, completes fenced on the claim token; retry 2 s x 2^n capped at 300 s, the 10th or any non-retryable failure exhausts the intent). `ddd:relay` runs the wakeup step after each outbox step. |
| D14 | `Runtime\Wakeup\PostgresNotifyRelayWakeup` (`pg_notify` on the writer's connection: delivered at COMMIT, dropped on ROLLBACK), poked by `DbalPostgresOutboxStore::append` and `DbalWakeupScheduler::schedule`; `PostgresListenWaiter` (LISTEN + `pgsqlGetNotify`, timed-sleep fallback) used by `ddd:relay` when idle. |
| X3 start mode | `tangible_ddd.process.inband_start` (default `false`) passed to the core runner by `Factory::processRunner` when core has the option (CR sfp-1). |
| sfc-5 | `DbalOutboxAdministration::retry()` already removed the DLQ entry (wave 2); `ddd:ops:dlq:retry` exposes it. |
| ops | `ddd:ops:dlq:list`, `ddd:ops:dlq:replay`, `ddd:ops:dlq:retry`, `ddd:ops:stranded` (`--resume`, `--fail`, `--rearm`), `ddd:ops:pause`, `ddd:ops:resume`. |
| D10 (ruling #78) | `DbalBehaviourWorkflowRepository`, `DbalWorkItemRepository`, `DbalWorkflowIgnitionLedger` (`ddd_workflow_ignitions`, primary key on the dedup key). |
| schema | `schema/postgres/005_processes.sql`, `006_process_waits.sql`, `007_wakeups.sql`, `008_workflows.sql`. |
| carry-overs | `tangible_ddd.middleware.act_bracket` = core `CorrelationMiddleware`; `tangible_ddd.integration_bus` = core `OutboxIntegrationEventBus` (port form) behind `FactClassRecordingEventBus`; the two `Runtime\Transitional` classes are deleted. Boot provides `SymfonySignalDispatcher` (PSR-3 warning + `DddSignal` event), the app clock and the app logger to `HostDefaults`. |

Every adapter is tested directly on Postgres 16 (own database, no transaction wrapper). Lock contention, release, "later succeeds" (a child `php` process holds the lock, then exits), namespacing and the reentrant balance are proven across two sessions.

## CR sfp-1: `ProcessRunner` start mode (core, additive)

**Need.** Register X3 / 5.2 and scenario `process.start-from-web`: on sf, `ProcessRunner::start()` persists the process and writes a `Continue` intent in the caller's transaction, and the first step runs in a worker; `ddd.process.inband_start: true` restores the in-band first step. `ProcessRunner` is final and the lifecycle set-up (`prepare_start`, `create_process_steps`) is private, so a host cannot implement this mode outside core.

**Request.** An optional trailing constructor parameter `bool $inbandStart = true` (R2 style; WordPress and pdo keep today's behaviour). With `false`, `start()` runs `prepare_start()`, then in one `atomically()` (joining the caller's open transaction) `insert()` plus `wakeups()->schedule(WakeupIntent::continuation(prefix, id, 0, now))`, and returns without running a step. `ignite()` is unaffected (it already runs in a worker). The parameter name may also be `$inband_start`.

**sf today.** `Factory::processRunner()` passes the option by name when `Factory::startModeParameter(ProcessRunner::class)` finds it. Until then it logs a warning at construction ("start() runs the first step in-band") and the runner keeps the in-band start. A web request that calls `start()` on a pooled connection would then take an advisory lock on it; that is the remaining gap until core ships this.

## CR sfp-2: one wake door `ProcessRunner::wake(WakeupIntent $i)` (core, additive)

**Need.** The `ddd_wakeups` handler must route every `WakeKind`. The wave-2 runner has `continue_scheduled(int)` and `handle_timeout(int, int)`, but no door for `ResumeRetry` (register 3.7: "On LockNotAcquired the runner schedules a ResumeRetry"), and neither door checks `expectedStatus` (3.6: "every wake handler is stale-safe ... no-ops unless expectedStatus and stepIndex still match").

**Request.** `public function wake(WakeupIntent $i): void` on `ProcessRunner`: under the process lock, re-read, no-op unless `expectedStatus` and `stepIndex` match, then dispatch by kind (Continue, Timeout, ResumeRetry). Lock and fence failures propagate unchanged (the sf handler retries them).

**sf today.** `Runtime\Wakeup\ProcessRunnerWakeTarget` calls `wake()` when the runner has it; otherwise Continue → `continue_scheduled()`, Timeout → `handle_timeout()`, and ResumeRetry / Deliver throw `WakeKindUnsupported`, which exhausts the intent (it is kept for `ddd:ops:stranded`, never dropped).

## CR sfp-3: `OutboxRecord::$event_class` (core, additive; CR sf-1 restated)

The core `OutboxIntegrationEventBus` builds an `OutboxRecord` without the fact's PHP class, which sf delivery needs for marker subscriptions (D2). Until core adds `?string $event_class = null` to `OutboxRecord` and fills it in the bus, `Runtime\FactClassRecordingEventBus` wraps the core bus and scopes `get_class($event)` on the store (`DbalPostgresOutboxStore::withFactClass()`); `append()` already prefers `$r->event_class` when the property exists, so the decorator becomes a pass-through.

## Finding for core (no sf change): `#[Async]` re-schedules on its own continuation

`execute_forward()` checks `has_async_attribute()` before every run of the current step, including the run that `continue_scheduled()` starts for that same step. An `#[Async]` step therefore schedules a new continuation on every wake and never executes. Reproduced on the mem doubles at `8c74686`: a process `one(); #[Async] two()` stays `scheduled` after `start()` and three `continue_scheduled()` calls, and `two()` never runs. The ProcessRunner refactor should skip the check when the wake is the continuation of that step (for example, compare the Continue intent's step index).

## Additive sf API and behaviour changes (no request, listed for review)

- New public classes: listed in the table above, plus `Runtime\Wakeup\IRelayWaiter`, `IWakeupRelayStep`, `IProcessWakeTarget`, `WakeKindUnsupported`, `WakeupRelayReport`, `Lock\PooledConnectionRefused`, `Runtime\DddSignal`, `Runtime\LazyProcessEntry` (internal; breaks the runner → registry → runner construction cycle), `Runtime\HostDefaultsInstaller` (internal).
- `DbalWakeupScheduler::exhaust()`, `exhausted()`, `rearm()` (sf-only, not on the port) and the `ddd_wakeups.exhausted_at` column: an exhausted intent is never claimed and does not count as live for the stranded scan.
- `DbalPostgresOutboxStore::__construct()` gains optional trailing `?IRelayWakeup $wakeup, string $wakeupConsumer`; `withFactClass()`.
- `RelayCommand::__construct()` gains optional trailing `?IWakeupRelayStep $wakeups, ?IRelayWaiter $waiter`. Its summary line adds "wakeups projected" and "stranded re-queued".
- `WakeupRelay` stamps `BusNameStamp(tangible_ddd.messenger.bus)` so `messenger:consume ddd_wakeups` dispatches on the delivery bus.
- Config: `tangible_ddd.process.{inband_start, pooled_connection, stranded_after_seconds, wakeup_lease_seconds, stranded_scan_seconds}`, `tangible_ddd.relay.listen` (default true), `tangible_ddd.messenger.{wakeup_transport, wakeup_dsn}`. `prependExtension` adds the `ddd_wakeups` Doctrine transport with `max_retries: 0` (the intent row owns the wake budget, 5.1).
- Service ids: `tangible_ddd.process_store`, `.wakeup_scheduler`, `.process_lock`, `.process_runner` (public, alias `ProcessRunner`), `.process_entry`, `.wake_target`, `.wakeup_handler`, `.wakeup_relay`, `.relay_wakeup`, `.relay_waiter`, `.signal_dispatcher`, `.host_defaults`, `.workflow_repository`, `.work_item_repository`, `.workflow_ignitions`, `.command.ops.*`. Interface aliases: `IProcessStore`, `IWakeupScheduler`, `IProcessLock`, `IRelayWakeup`, `IBehaviourWorkflowRepository`, `IWorkItemRepository`.
- **Behaviour:** `tangible_ddd.process_entry` (config) now defaults to the bundle's runner (resolved lazily) instead of none, so `#[StartsOn]` / `#[Awaits]` work without configuration; a configured id still wins.
- **Behaviour:** the act bracket is the core class, so audit `openedAt` comes from `HostDefaults`' clock (the bundle provides `tangible_ddd.clock` at boot) and command ids honour `DeterministicCommandId` (wave-1 open minor 2).
- **Behaviour:** signals that went to core's `LoggingSignalDispatcher` (`error_log` without a host logger) now go to the PSR logger at `warning` and to `DddSignal` listeners; a throwing listener is logged and ignored.
- The conformance `SfHostFixture` now uses the core act bracket and bus and the Postgres advisory lock, so the wave-3 `lock.*` ids can be wired on sf in round 3.
- The ops commands call the ports directly (with lease, status and lock guards). Register 3.10 describes the repairs as `ITransactionalCommand`s and 5.1 names `ddd:ops:list` / `ddd:ops:repair`; those need the core `IOperatorView` and repair commands (core wave 3), and can be added over the same adapters.

## Requests to other owners

- **packaging:** none. `packages/ddd-symfony/composer.json` is unchanged (`PackageManifestTest` pin holds).
- **conformance (round 3):** the sf fixture is ready for `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance`, `lock.namespace` on Postgres; the process ids need the core runner refactor plus CR sfp-1 / sfp-2.
