# Wave 3: conformance-process change requests

Author: conformance-process (wave 3, rounds 2-3). Branch `wave3/conformance-process`. Owned paths: `packages/ddd-conformance/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (section 4, section 8 wave 3), [wave2-notes.md](wave2-notes.md), [wave3-notes.md](wave3-notes.md), [wave3-core-change-requests.md](wave3-core-change-requests.md) (W3C-R6).

Every item is additive. Each one adds a new interface, a new class, a new constant or static method on a conformance class, or an optional constructor argument. `HostFixture`, `ScenarioRows` and every ratified core port are unchanged. The sf and wp host fixtures compile and run unchanged: the sf conformance group was re-run against this branch and gave 23 tests, the same 2 skips as before.

## What this round did

| Task | Where |
|---|---|
| Scenario cases for every wave-3 id | `src/Scenarios/{ProcessDelivery,Process,Lock,Concurrency,FreshProcess,WebStart}Scenarios.php` |
| The 13 mem ids due in wave 3, green on the core `ProcessRunner` + `Drain` | `src/Mem/MemHostFixture.php`, `tests/Mem/Mem{ProcessDelivery,Process,Lock}ScenariosTest.php` |
| Process fixtures | `src/Fixtures/Process/*` (3 facts, `StepCommand`, `ProcessJournal`, 6 `LongProcess` classes) |
| sfc-2 option (b) for `delivery.delayed-once` | `DeliveryScenarios::assertDueWithin()` |
| CR sf-7 case in `cmd.commit-failure` | `CommandScenarios::workSwallowsAStatementError()` (needs `StatementErrors`) |
| CR sf-3 assertion in `relay.lease-fencing` | `RelayScenarios::lateHolderOfARelayStep()` (needs `RelayRace`) |
| Catalogue: exact wave-3 lists per host, `CASES` | `src/ScenarioCatalogue.php`, `tests/CatalogueTest.php` |
| Seams documented for host fixture authors | `README.md` |

## CR-W3CP-1: `ProcessHost`, `ProcessWorker`, `ProcessRow` (new optional seam)

- **What.** `ProcessHost` has these members:
  - `wireProcesses(starts, awaits)` and `worker(int $n = 1): ProcessWorker`.
  - The ports `processStore()`, `wakeups()` and `operatorView()`, plus `processConsumer()` and `processLockKey($id)`.
  - Read-back: `processRow($id): ?ProcessRow`, `processIds(?class)` and `pendingWakeups()`.
  - Lock faults: `holdProcessLockElsewhere`, `releaseProcessLockElsewhere`, `failNextProcessLockAcquire`, `processLockAcquisitions`, and the interleaving point `beforeNextProcessLockAcquire(callable)`.
  - `failNextWakeHandoff($reason)`.

  `ProcessWorker` has `processRunner()`, `processLock()`, `deliverFact()` and `drainOnce()`. `ProcessScenarioCase` is the base of the process cases: without the seam it skips with this id, and its `start()` helper is start-mode neutral.
- **Why.** The register names the scenarios but no fixture surface for them. The task asks for "a ProcessHost seam exposing the runner, store, lock, wakeups, Drain::runOnce / ProcessRunner::wake (W3C-R6), a second-connection simulator, clock control". The clock is already `HostFixture::advanceClock()`. Concurrency is expressed as worker 2 acting at worker 1's interleaving point. That is host-neutral: an in-process second connection on sf/pdo, a separate runner object graph on mem.
- **Read-back gap.** `IProcessStore` cannot list rows or return `ignition_key`, so `processRow()` / `processIds()` live on the seam rather than on the port.

## CR-W3CP-2: `RelayRace` (new optional seam)

`raceNextRelayAfterSubmit(callable $competitor)`: the competitor runs between the relay step's submission and `accept()`, as another connection. On mem the outbox's view of "transaction open" comes from `Support\ConnectionView`, and the competitor's writes survive the relay's rollback. It is needed for the CR sf-3 assertion. Without it that part of `relay.lease-fencing` does not run.

## CR-W3CP-3: `StatementErrors` (new optional seam)

`runFailingStatement()`: one rejected statement inside the open transaction. It is needed for the CR sf-7 case. The case accepts either outcome, but never success with the rows gone. mem takes the commit branch. `tests/Simulated/AbortingEngineCommandScenariosTest` runs the abort branch on a simulated 25P02 engine.

## CR-W3CP-4: `FreshProcesses`, `FreshRun`, `Support\FreshProcessBoot` (new optional seam)

- **What.** `FreshProcesses` has four methods:
  - `publishInFreshProcess($fact, bool $killAfterCommit): string`
  - `drainInFreshProcess(): FreshRun`
  - `deliverInFreshProcess($class, $wrapped): FreshRun`
  - `startInFreshProcess($process, ?string $dieAfterCommand): FreshRun`

  `FreshProcessBoot::boot()` is what a fresh process registers: the effect subscriber writing `effect:{widget_id}`, the conformance process wiring, and `ProcessJournal::bind()`. Step effects become scenario rows `cmd:{label}:{deterministic command id}`.
- **Why.** Closures cannot cross php processes, so the cross-process operations are named. Everything a fresh process does is observable as committed rows.
- **`process.crash-mid-step` resume.** `ResumeStrandedProcess` is ruled to wave 4 (WP8-10 ruling), so the scenario resumes the stranded row with the mechanism that repair will use: a `ResumeRetry` intent `expectedStatus = running` at the row's current version (CR-W3C-3). If wave 4's repair command differs, this line of the scenario switches to it.

## CR-W3CP-5: `WebRequests` (new optional seam, sf)

`inWebRequest(callable)` and `bootInBandStartOnPooledDsn(): ?\Throwable`, for `process.start-from-web`.

## CR-W3CP-6: catalogue and mem fixture API

- `ScenarioCatalogue::CASES` (id => abstract case, up to wave 3), `scenarioCase($id)` and `casesFor($host, $wave)`. `CatalogueTest` pins `CASES` against reflection and pins the exact wave-3 lists of register section 8 for all four hosts (13 / 37 / 24 / 23).
- `MemHostFixture` is no longer `final`. Its members are `protected`, and its constructor gains an optional `StartMode $startMode = StartMode::InBand` after `$transportSharesConnection`. It now also implements `ProcessHost`, `RelayRace` and `StatementErrors`. `runnerTransients()` returns `['resume_argument' => …]` from worker 1's runner, where it used to return null.
- `MemSimulatedHostFixture` (new) is not a host. It simulates `FreshProcesses` and `WebRequests` in-process. Its tests are group `simulated`, never `mem`.

## Behaviour changes to existing scenarios

1. **`delivery.delayed-once` (sfc-2 option (b)).** The transport's due time must lie in `[requested, max(requested, submit time)]`, both for the retried fact and for the legacy row. D is raised from 120 s to 7200 s, above the 3600 s retry gap, so a delay applied a second time still falls outside the window. mem and wp report the requested time exactly and keep passing.
2. **`cmd.commit-failure`** runs the sf-7 case only on a fixture implementing `StatementErrors`.
3. **`relay.lease-fencing`** runs the sf-3 case only on a fixture implementing `RelayRace`.

## Requests to other owners

- **W3CP-R1 (symfony).** Remove the `sfc-2` skip of `delivery.delayed-once` once `SfHostFixture::transported()` reports `TransportedFact::$dueAt` on the host clock. A probe against this branch, run outside the repo on Postgres 16, got `dueAt` = requested − D. That is the Doctrine `available_at` on the wall clock, which does not move with `advanceClock()`. Option (b) still needs both times on one clock: translate `available_at` by the fixture's wall/host offset, or give the transport the host clock.
- **W3CP-R2 (symfony).** To run the 23 wave-3 sf ids, implement the following on `SfHostFixture`:
  - `ProcessHost`, with worker 2 on a second DBAL connection and `holdProcessLockElsewhere` via `pg_try_advisory_lock` on it.
  - `FreshProcesses`, as `bin/console` children.
  - `WebRequests`.
  - Optionally `RelayRace` and `StatementErrors`, to activate the sf-3 / sf-7 parts on Postgres.

  Then extend `ProcessDeliveryScenarios`, `ProcessScenarios`, `LockScenarios`, `ConcurrencyScenarios`, `FreshProcessScenarios` and `WebStartScenarios`. `ScenarioCatalogue::casesFor('sf', 3)` lists them.
- **W3CP-R3 (wp).** Implement `ProcessHost` and `FreshProcesses` on `WpHostFixture`. `failNextWakeHandoff` maps to the Action Scheduler projection at schedule time. Extend the same cases except `WebStartScenarios`, and override `ConcurrencyScenarios::test_lock_namespace` with a skip (`-` on wp, register 3.7).
- **W3CP-R4 (pdo-default).** A pdo `HostFixture` (MySQL 8, run twice, the second time with `ATTR_EMULATE_PREPARES = true`) plus `ProcessHost` and `FreshProcesses` over separate `php` processes and `DDD_CLOCK_OFFSET`, extending every case `casesFor('pdo', 3)` lists. `WakeHandoffFaults` and `InterleavingProcessLock` are reusable as they are.
- **W3CP-R5 (core, information only).** No core change is needed. Two observations:
  - With the CR sf-8 / CR-PDO-6 ruling, `relay.lease-fencing` gains the "re-claims count as attempts" assertion in wave 4. This round does not add it.
  - The runner's stale guards are layered: status, step index and the await mechanism. A single removed guard is caught only by the "live process" half of `process.stale-wakeup`, which this round added for that reason.
