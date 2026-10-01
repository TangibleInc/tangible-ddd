# Wave 4: conformance-4 change requests

Author: conformance-4 (wave 4). Branch `wave4/conformance-4`. Owned paths: `packages/ddd-conformance/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (section 4 wave-4 cells, section 8 wave 4, 3.8, 3.11), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (CR-W4CE-1, -5, -9), [wave4-core-process-change-requests.md](wave4-core-process-change-requests.md) (CR-W4P-1..7, W4P-R2, W4P-R3), [wave3-wp-conformance3-change-requests.md](wave3-wp-conformance3-change-requests.md) (W3-WPC3-2), TXP demands D1, D3, D6, D7, D10, D14 (`txp/.docs/specs/module-map.md`).

Every item is additive. Each one adds a scenario case, a new optional seam interface, a fixture class, a static helper, or an interface on a conformance support class. `HostFixture`, `ProcessHost`, `ProcessWorker`, `ScenarioRows` and every ratified core port are unchanged. No core, pdo, wp or sf file was touched. The behaviour changes that an existing host can observe are listed under "Compatibility impact".

## What this round did

| Id | Case | Needs | mem result |
|---|---|---|---|
| `process.alarm-long` | `AlarmScenarios` | ProcessHost | green (also under `StartMode::Deferred`, simulated) |
| `process.await-keyed-precheck` (new, CR-W4C4-1) | `AwaitScenarios` | ProcessHost | green (also deferred) |
| `process.await-any-cancellation` (new, CR-W4C4-1) | `AwaitScenarios` | ProcessHost | green (also deferred) |
| `process.await-all-dynamic` (new, CR-W4C4-1) | `AwaitScenarios` | ProcessHost | green (also deferred) |
| `codec.large-payload` | `CodecScenarios` | HostFixture | green |
| `decode.unknown-class` | `DecodeScenarios` | ProcessHost + `ProcessDecodeFaults` | green |
| `effect.journal-reuse` | `EffectScenarios` | `EffectHost` | green |
| `workflow.fact-ignition-once` | `WorkflowScenarios` | `WorkflowHost` | green |
| `wakeup.post-commit` (sf only) | `PostCommitWakeupScenarios` | `PostCommitWakeups` | `-` on mem; green on the simulation |
| `relay.lease-fencing` + CR-PDO-6 | `RelayScenarios` (existing) | HostFixture; ProcessHost / RecordsSignals parts optional | green |

`CatalogueTest` pins the wave-4 lists of register section 8 per host, plus the three D3 ids, and requires a mem scenario for every id due on mem by wave 4. It also requires that every wave-4 id lives in a case that no wave-3 host class extends. After a clean `composer install`, `vendor/bin/phpunit --group mem` has 62 tests, all green, with 0 skips. The full package has 95 tests (mem, catalogue and simulated groups).

## CR-W4C4-1: three D3 scenario ids for TXP process-kernel (register edit requested)

- **What.** `ScenarioCatalogue::WAVES` gains three rows. Each one has its scenario in `AwaitScenarios`:

  | Id | Scenario | Expected result | mem | pdo | wp | sf |
  |---|---|---|---|---|---|---|
  | `process.await-keyed-precheck` | keyed await on a ref the step mints (`step_ref`), with a checkpoint and an alarm; then a precheck process whose job result committed during the step's dispatch | at dispatch the await, its `(class, ref)` route, the checkpoint and the alarm are committed; a foreign key writes nothing; the minted key resumes only its process and cancels the alarm; the precheck resumes in the same wake; the late fact completes with no error and no retry, and writes nothing | 4 | 4 | - | 4 |
  | `process.await-any-cancellation` | three `AwaitAny(keyed answer).cancelledBy(WidgetScrapped{widget}).within(1h)` processes, two on one widget | the cancellation compensates both processes of its widget, once each (`Cancelled by WidgetScrapped`), with no intent left, and leaves the third alone; a late answer does not resurrect a cancelled process; the third resumes on its own answer; a cancellation after completion is a no-op | 4 | 4 | - | 4 |
  | `process.await-all-dynamic` | `AwaitAll::keyed` over keys computed and checkpointed at step time (`children_first`) | the routes shrink as keys arrive; a duplicate, unknown or other process's key writes nothing; the alarm is not re-delayed; resumes once with every key; an empty set does not suspend | 4 | 4 | - | 4 |

- **Why.** The task requires them: TXP process-kernel needs D3 end to end on ddd-symfony right after round 2 ("keyed await on a ref the process mints, persisted before dispatch; a register-then-check precheck that absorbs a late fact; any-of awaits including cancellation facts; AwaitAll over a checkpointed dynamic key set"). Register section 4 has no D3 cell. The core runner tests (`ProcessRunnerWave4Test`) cover D3 on core doubles only, not through each host's store, resume subscriber and `findWaitingFor()`.
- **wp is `-`.** The new mechanisms are not covered by wp's rollback fixtures. CR-W4P "Compatibility impact" says wp should not use them until its 7.3 fixtures cover them, and register O9 says wp runs D-scenarios only when a WP consumer needs them. The wp adapters can express the scenarios: AwaitAny goes through the runner's ancestor lookup (CR-W4P-6), and keyed awaits are filtered by `accepts()`. So the cell can become a wave once wp adds rollback coverage.
- **Register edit requested (coordinator).** Add the three rows to section 4 (the count becomes 47). Add them to the section 8 wave-4 conformance lists: mem 8 ids, pdo 7, wp 3 (unchanged), sf 9.
- **Compatibility.** These are new ids in new cases. `dueBy($host, 3)` and `casesFor($host, 3)` are unchanged, so the wave-3 pins of pdo, sf and wp (`PdoCatalogueTest`, `SfCatalogueTest`, `WpCatalogueConformance`) still hold.

## CR-W4C4-2: `EffectHost` seam (effect.journal-reuse, D1)

- **What.** `interface EffectHost { effectJournal(): IEffectJournal; effectBus(array $handlers): CommandBus; }`. `effectBus()` is the host bus with the core `EffectMiddleware` between the act bracket and Transaction (CR-W4CE-1), over `effectJournal()` and `HostFixture::boundary()`. `RecordEffect` must reach `RecordEffect::apply()`.
- **Scenario.** `WidgetRegistered` maps to `ChargeWidget` (an `IExternalEffectCommand`). The translator is registered with the core `SubscriptionRegistrar` on `HostFixture::subscriptions()`, and facts are delivered through `HostFixture::deliver()`. The scenario then checks, in order:
  1. If perform succeeds and record throws, the result is journaled. The redelivery records it without performing again, and so does a dispatch under a new command id. The bus returns the `EffectResult`.
  2. If record keeps failing, the subscriber's ledger attempts reach the budget. The core invoker then commits `ChargeFailed` once, under `uuid5(event_id, "{subscriber}#failure")`, and a later delivery does not fire it again.
  3. A repair whose transaction rolls back keeps the entry. A repair that commits `invalidate()` performs again, and the new result is journaled.
- **Why a seam.** `HostFixture::commandBus()` is frozen as act bracket → Transaction → … and has no effect stage. Adding the stage there would change every host's bus.

## CR-W4C4-3: `ProcessDecodeFaults` seam (decode.unknown-class)

- **What.** `forgetProcessClass(int $id, string $missingClass)`, `storedProcessStatus(int $id): ?string`, `quarantineReason(int $id): ?string`.
- **Why.** No port can corrupt a stored class. `ProcessHost::processRow()` cannot be relied on to return an undecodable row (on mem it returns null), so the scenario reads the status and `quarantine_reason` columns directly.
- **Scenario.** Two hop processes; one is made undecodable. The drain pass quarantines it (`failed` plus a reason, no new status value, R5) and completes the healthy one. Later passes run no step of the quarantined row. For a suspended undecodable row: a fact for it fails no delivery, a fact for another process still resumes that process, and its alarm wake quarantines it without compensation.

## CR-W4C4-4: `WorkflowHost` seam (workflow.fact-ignition-once, D10)

- **What.** `workflowIgnitionLedger(): IWorkflowIgnitionLedger`, `workflowRepository(): IBehaviourWorkflowRepository`, `workflowIgniter(): WorkflowIgniter`. All bind to the per-test schema.
- **Scenario.** `CronExportWorkflow` (`#[StartsOn(CronEntryDue)]`, key `WorkflowIgnitionKey::perMinute`) is registered with `workflowIgniter()->register()`. The scenario checks:
  - The same fact delivered twice gives one workflow and one run.
  - A direct re-ignition of that fact (another worker or consumer) loses the ledger claim and is told the winner's id.
  - A second tick in the same minute gives nothing new. The next minute gives the next run. A declined fact gives nothing.
  - A failed start keeps its ignition and fails the delivery. The redelivery starts the same workflow exactly once (CR-W4P-6 `Restarted`).
- **Mem.** `Mem\InMemoryWorkflowIgnitionLedger` and `Mem\InMemoryWorkflowRepository`, both enlisted in the boundary. The ledger copies core's test double, because W4P-R2 (promote it to `Testing`) is not done.

## CR-W4C4-5: `PostCommitWakeups` seam (wakeup.post-commit, D14; sf)

- **What.** `startRelayWorker()`, `relayUntilTransported(string $eventId, float $timeoutSeconds): ?float` (wall seconds, or null), `wakeupArrives(float $timeoutSeconds): bool`, `suppressNextWakeup()`, `relayPollIntervalSeconds(): float`, `stopRelayWorker()`. The worker is the production LISTEN + poll loop, driven in steps on its own connection. It is started before the scenario commits, so a delivery can only come from a wakeup or a poll.
- **Scenario.** Each case runs with the worker idle and listening:
  - A commit is relayed in under 1 s.
  - With the wakeup suppressed, nothing arrives, and the poll delivers within interval + 1 s.
  - A rolled-back command sends no wakeup and leaves nothing pending.
  - The scenario refuses a poll interval of 1 s or less, because then the two paths cannot be told apart.
- **Simulation.** `MemSimulatedHostFixture` implements the seam with no wall time: each outbox row committed since the last look counts as one wakeup. That way the scenario's logic runs here before sf implements the seam.

## CR-W4C4-6: additive changes to existing conformance classes

- `RelayScenarios::test_relay_lease_fencing` gains the CR-PDO-6 assertion (`expiredLeaseReclaimsAreCounted()`). A fact's submitter dies after every claim. Each re-claim of an expired lease is handed out with `attempts` = n. The re-claim that reaches `max_attempts` is dead-lettered inside `claim()`: the next `relayOnce()` neither claims nor transports it, and it sits in the DLQ with attempts equal to the budget and `IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR` in the error. Two checks run only where the host has the seam: with `ProcessHost`, it must be listed in the operator view's relay layer; with `RecordsSignals`, exactly one `OutboxDeadLettered` must be emitted.
- `Support\RecordingOutboxStore` now `implements IReportsClaimDeadLetters`. It forwards to the inner store, or returns `[]` when the inner store lacks the interface. Before this, the mem relay step (and any host relaying through the decorator, such as pdo) never saw claim-time dead letters, so the core relay emitted no `OutboxDeadLettered` for them. The signal assertion was red without this change.
- `Fixtures\Process\ProcessJournal`: new `markRow()`, `hasRow()` and `widgets()`. Each `$sent` entry gains a `widget` key.
- `ConformanceTestCase::wrap()` now defaults the correlation id to `uuid5(CORRELATION_NAMESPACE, 'corr-' . event id)`. This closes W3-WPC3-2. An explicit id is kept. Pinned by `tests/WrapTest.php`.
- `MemHostFixture` implements the three mem-side seams. A protected `bus()` helper composes the existing bus, optionally with the effect stage. `commandBus()` is unchanged, and `MemHostCompositionTest` still pins "no conformance-owned middleware but the handler map".

## Requests to other owners

- **W4C4-R1 (pdo, CR-PDO-6 ruling).** `PdoOutboxStore::claim()` must count expired-lease re-claims and dead-letter at claim. Implementing `IReportsClaimDeadLetters` is the simplest way to get the signal and the operator view. Measured on this branch against MySQL 8: `relay.lease-fencing` is red in both prepare modes ("re-claim 1 of an expired lease counts as attempt 1: 0 is not 1"). The other 78 pdo conformance tests are green.
- **W4C4-R2 (wp, CR-PDO-6 ruling).** The same change in the v8 wp outbox store. The wp `relay.lease-fencing` stays red until it lands. Not run here (it needs the WP harness). Optional: drop `WpConformanceRuntime::onTheWire()` now that `wrap()` sends UUIDs.
- **W4C4-R3 (sf).**
  - Implement `EffectHost` (core `EffectMiddleware` between `act_bracket` and `transaction` with `tangible_ddd.effect_journal`, per wave4-sf-a), `WorkflowHost` (`DbalWorkflowIgnitionLedger` and the sf workflow store, plus the container's `WorkflowIgniter` per W4P-R1 items 2-3), `ProcessDecodeFaults` and `PostCommitWakeups` (`PostgresListenWaiter` and `PostgresNotifyRelayWakeup`, with a short poll interval) on `SfHostFixture`.
  - Extend `AlarmScenarios`, `AwaitScenarios`, `CodecScenarios`, `DecodeScenarios`, `EffectScenarios`, `WorkflowScenarios` and `PostCommitWakeupScenarios`, and pin the 9 wave-4 ids in `SfCatalogueTest`.
  - Measured on this branch: the existing sf conformance suite is green on Postgres 16 (49 tests), including the new `relay.lease-fencing` assertion.
- **W4C4-R4 (pdo).** Extend `AlarmScenarios`, `AwaitScenarios`, `CodecScenarios`, `DecodeScenarios` and `EffectScenarios` (7 ids), with `ProcessDecodeFaults` and `EffectHost` (a pdo effect journal) on `PdoHostFixture`. If its cap differs from the core default, override `CodecScenarios::outboxPayloadCap()`.
- **W4C4-R5 (wp).** Extend `AlarmScenarios`, `CodecScenarios` and `DecodeScenarios` (3 ids), with `ProcessDecodeFaults`. Leave the D3 ids out (`-`).
- **W4C4-R6 (core-process, finding).** A due wake for a quarantined process is retried by the drain on every pass: the intent stays pending, appears in `wakesRetried`, and is kept after the wake budget. `resume_with_outcome()` already skips a `QuarantinedProcess`. The suggested fix is that `ProcessRunner::wake()` / `continue_scheduled()` / `handle_timeout()` treat it as terminal too, so the intent completes as a no-op. `decode.unknown-class` does not assert this, because the register only requires "worker continues". It can once core decides.
- **W4C4-R7 (owner of `packages/ddd-core/src/Testing/**`, W4P-R2).** Promote the ignition ledger double to `Testing\InMemoryWorkflowIgnitionLedger`. `Mem\InMemoryWorkflowIgnitionLedger` can then be deleted.
- **W4C4-R8 (coordinator).** Ratify CR-W4C4-1 and make the register edit, then CR-W4C4-2..6.

## Not done here

- L6 conformance case (wave4-sf-a "Request (conformance owner)": an ORM-like unit-of-work seam). It is not in this task. The sf kernel test remains its proof.

## Compatibility impact

- **Host classes written for wave 3** compile and run unchanged. No method was added to a case they extend, except the extra assertion in `relay.lease-fencing`.
- **`relay.lease-fencing`** now requires the CR-PDO-6 rule on every host where the id is due: mem (green), sf (green), pdo (red until W4C4-R1) and wp (red until W4C4-R2). This is the wave-3 ruling taking effect, as intended for wave 4.
- **`wrap()`'s default correlation id** changed from `corr-{event id}` to a uuid5. No scenario or host asserted the old form. The pdo and sf suites are green with it.
- **`ProcessJournal::$sent`** entries have one more key.
- **`RecordingOutboxStore`** is now an `IReportsClaimDeadLetters`. For an inner store without the interface it returns `[]`, so the relay sees no change.
- **No change** to core, ddd-symfony, ddd-wp or pdo code, and no schema change.
