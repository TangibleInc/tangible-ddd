# Wave 3 (rounds 2-3): wp-conformance3 change requests

Author: wp-conformance3 (branch `wave3/wp-conformance3`, based on `2b8348c`). Owned paths: `tests/Integration/Conformance/**`, the `conformance-wp` block of `tests/harness/run.sh`, `packages/ddd-wp/wordpress/Adapter/WpdbWakeupScheduler.php` (W3C-R1 only), and this file.

No ratified interface, frozen FQCN, persisted name, hook signature or schema changed. The one adapter change (W3C-R1) is a fix inside an existing UPDATE statement. Everything else is fixture code under `tests/Integration/Conformance/`.

## What this round did

| Task | Where |
|---|---|
| W3C-R1 closed and pinned | `WpResumeRetryConformance` (4 tests), fix in `WpdbWakeupScheduler::schedule()` |
| Host fixture on the final v8 adapters | `Support/WpConformanceRuntime.php`, `WpHostFixture.php` |
| Wave-2 stand-ins deleted | `Support/{ActionSchedulerTransport,LedgerGatedSubscriptions,ScenarioTime,RecordingOutboxStore,clock-functions}.php` |
| `ProcessHost` | `WpHostFixture` (+ `Support/{WpProcessWorker,ConnectionSwitch,CountingProcessLock}.php`) |
| `FreshProcesses` | `bin/fresh.php`, `Support/FreshPhp.php` |
| The 24 wave-3 wp ids + the 12 wave-2 ids | `Wp{ProcessDelivery,Process,Lock,Concurrency,FreshProcess}Conformance.php`; `WpRelayConformance` drops its two v8 skips |
| Gate at wave 3 | `WpCatalogueConformance` (register section 8 wave 3 pinned, 24 ids), `bin/check-due.php` and `run.sh conformance-wp` default to wave 3 |

## W3-WPC3-1 (wp, fix; closes W3C-R1)

- **Finding.** The v8 `WpdbWakeupScheduler` already accepted `WakeKind::ResumeRetry` and projected it to `{prefix}_ddd_wakeup` with `['key' => idempotency key]`. The hook's callback (`hooks.php` → `WpWakeBracket::resumeRetry()`) calls `ProcessRunner::wake()` with the intent read back from the row, so a `running` retry is checked against the version its key carries. `WpResumeRetryConformance` pins all three entry paths: a direct schedule, a contended timeout fired on `{prefix}_await_timeout`, and an in-band start that cannot lock. `lock.contention` and `process.crash-mid-step` run the same path inside the shared scenarios.
- **Fix.** Scheduling a key that is `done`, `cancelled` or `firing` again (the re-arm UPDATE) wrote `step_index = 0` and `expected_status = ''` for an intent that has neither. A ResumeRetry of a just-started process (key segment `-`) then came back as a retry of step 0. The UPDATE now stores NULL, as the INSERT does. Test: `test_re_arming_a_resume_retry_key_keeps_its_step_index_and_expected_status`.
- **For wp (information).** With core's `wake()` merged, the "closed without a re-run" fallback in `WpWakeBracket::resumeRetry()` only serves a consumer-built runner without `wake()`. The transitional `ActionSchedulerWakeupScheduler` still refuses ResumeRetry, as allowed. W3C-R3 noted that the core-owned `tests/Unit/Process/ProcessLockAcquisitionTest` pins the hotfix half because the transitional scheduler cannot take ResumeRetry. Its owner can now point the re-queue half at the v8 scheduler.

## W3-WPC3-2 (conformance, request): UUID correlation ids in `ConformanceTestCase::wrap()`

- **What.** `wrap($fact, $eventId)` defaults the correlation id to `corr-{event id}` (41 characters). On wp, a fact-ignited process inherits the envelope's correlation id into the 0.6 column `long_processes.correlation_id CHAR(36)`. The insert fails ("Processing the value for the following field failed: correlation_id"), the ignition subscriber fails, and the `.process` delivery ids, `process.ignition-race` and `process.manual-start-in-drain` go red. pdo (`VARCHAR(64)`) and sf do not hit this.
- **Why it is not a wp bug.** On wp the wire never carries such an id: a relayed envelope carries the outbox row's UUID. Widening the column is not an additive schema change (R5) and belongs to wp.
- **Workaround in place.** For hand-built envelopes only, the wp fixture's `deliver()` (worker 1, worker 2 and the fresh processes) replaces a non-UUID correlation id with its deterministic uuid5 (`WpConformanceRuntime::onTheWire()`). Relayed facts are untouched.
- **Request.** `wrap()` defaults to a UUID, for example `Uuid::v5(<ns>, 'corr-' . $eventId)`. The wp workaround can then go.

## W3-WPC3-3 (mapping, recorded; optional request to wp): `failNextWakeHandoff()` on wp

- **Mapping.** W3CP-R3 suggested the Action Scheduler projection at schedule time. On wp that projection is part of the state change: when Action Scheduler stores nothing (id 0), `WpdbWakeupScheduler::schedule()` throws and the process save rolls back with it. "The save commits, the transport is down" therefore cannot be expressed there. The wp fixture fails the next hand-off where wp actually hands an intent to its wake handler: when Action Scheduler runs the projected action. A callback ahead of ddd-wp's on the three wake hooks throws once, so the action fails before `WpWakeBracket` begins. The intent row stays `pending`, and the next relay tick re-projects it once its action is gone (register 5.3 step 3, wp form). `process.intent-survives-queue-failure` is green this way.
- **Optional request (wp).** Decide whether `schedule()` should store an unprojected intent and log, instead of throwing, when Action Scheduler returns 0. The relay tick's `reproject()` would then pick it up, which is what register 5.3 step 3 says for "a crash between save and enqueue". This is a behaviour choice, not a defect: today's choice keeps the 0.6 fallback (every intent on its legacy hook) strict.

## W3-WPC3-4 (fixture, recorded): isolation by wiping a fixed prefix

`HostFixture::setUp()` says SQL hosts derive a per-test schema from `ScenarioContext::uniqueName()`. On wp, the integration hook of a fact is `{IntegrationBehaviour::prefix()}_integration_{name}`. `WpLedgeredDelivery` derives the ledger and the consumer config from that hook prefix (`ddd_conformance` for every conformance fact). A per-test consumer prefix would put the outbox, processes and intents under one prefix and the ledger the gate uses under another.

The wp fixture therefore uses `ddd_conformance` as the consumer prefix. `setUp()` and `tearDown()` wipe everything under it: tables, options, Action Scheduler actions with their logs and groups, and hook callbacks. `setUp()` then installs schema v8 fresh. The effect is the same per-test freshness, and nothing is wrapped in a transaction. No request; recorded so reviewers do not read it as a skipped isolation rule.

## W3-WPC3-5 (fixture, recorded; information for wp): worker 2 on wp

- **Shape.** Worker 2 is a second MySQL session: its own transactions and its own `GET_LOCK` session. Its ports are the same v8 adapters, switched onto that session by swapping `$GLOBALS['wpdb']` around each worker-2 operation (`Support/ConnectionSwitch`). It has its own `ProcessRunner`, re-entrant lock and subscription registry.
- **Deliveries.** Worker 2's deliveries run through the core `IntegrationDelivery` over the same `WpDeliveryLedger`, not `do_action`. WordPress hooks are process-global, and `WpLedgeredDelivery::$bound` is a static table keyed by (hook, subscriber id). A second `WpHookSubscriptionRegistry` in the same php process would rebind worker 1's ignition and resume callbacks to worker 2's runner. Cross-process runs that really go through `do_action` are the `FreshProcesses` children.
- **For wp (information).** Two framework runners in one php process cannot both be WordPress-hook subscribers of the same consumer. This is harmless in production (one runner per consumer per request), and noted for anyone composing runners by hand.

## W3-WPC3-6 (fixture, recorded): `drainOnce()` and the DrainReport on wp

- wp has no production `Drain::runOnce()`. Its worker pass is the Action Scheduler queue: the recurring relay tick and the due actions. `ProcessWorker::drainOnce()` on wp is `WpConformanceRuntime::drainPass()`:
  1. `WpRelayTick` (port-form relay, `reproject()`, `restoreRedeliveries()`, `WpStrandedScan`);
  2. the consumer's due actions, claimed once at the start of the step in schedule order and run with the Action Scheduler runner. These are wakes through the ddd-wp hooks and `WpWakeBracket`, relayed facts and redeliveries. Due-ness is read on the host clock; async actions are always due.
- The `DrainReport` wake lists are read back from the intent table: keys that became `done` are completed, and keys that spent an attempt are retried (and exhausted at the budget). The relay result is the tick's.
- `operatorView()` is the core `PortOperatorView` over `WpdbOutboxAdministration` and `WpdbProcessStore`, because `WpOperatorView` does not implement `IOperatorView` yet (WP8-2).

## Not implemented on wp (optional seams)

`RelayRace` (CR sf-3 part of `relay.lease-fencing`) and `StatementErrors` (CR sf-7 part of `cmd.commit-failure`) are optional. They are not implemented, so those parts do not run on wp, and the ids still pass. A `RelayRace` on wp would need the competitor on a second session while the relay's wpdb transaction is open, and `WpdbTransactionDepth` is process-wide, so it needs care. It is left for a later round.

## Requests to other owners

- **W3-WPC3-R1 (conformance):** W3-WPC3-2.
- **W3-WPC3-R2 (wp, optional):** W3-WPC3-3.
- **W3-WPC3-R3 (packaging):** `run.sh` `usage()` still says "(wave-2 wp ids gated)" for `conformance-wp`. That line is outside this author's block; the gate itself now defaults to wave 3.
- **W3-WPC3-R4 (core or wp, whoever owns `tests/Unit/Process/**` after wave 3):** W3-WPC3-1, the `ProcessLockAcquisitionTest` note.
