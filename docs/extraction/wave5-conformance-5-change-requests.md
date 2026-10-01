# Wave 5: conformance-5 change requests

Author: conformance-5 (wave 5, rounds 2-3). Branch `wave5/conformance-5`, based on `extraction/ddd-packages` at `aae89ab` (round 1 merged: core-correctness, docs-housekeeping, sf-features, wp-redelivery-default). Owned paths: `packages/ddd-conformance/**` and this file.

Inputs: [wave5-core-correctness-change-requests.md](wave5-core-correctness-change-requests.md) CR-W5CC-6..8 (the conformance items of CR-W5CC-7 in particular), [wave5-sf-features-change-requests.md](wave5-sf-features-change-requests.md) CR-W5SF-R6, the coordinator's wave-5 task and rulings (CR-W5CC-1..8 ratified as written; `ITracksEffectState::find_entry`; the resume event id in the steps JSON; AwaitAll keeps the completing fact's id).

Every item is additive: new scenario ids in new abstract cases, new optional seam interfaces, an optional constructor parameter on the mem fixtures, a static counter on a fixture. No ratified interface changed (`HostFixture`, `ProcessHost`, `EffectHost`, `WorkflowHost` are untouched). A host class written for wave 4 runs unchanged: its cases gained no scenario method, and the two wave-3 scenarios that changed keep their wave-3 assertions on a host whose scheduler does not implement `ICarriesFacts`.

## What this round did

| Item | Where | Mem test class |
|---|---|---|
| CR-W5CC-7: `lock.acquire-error` branches on `ICarriesFacts` | `src/Scenarios/LockScenarios.php` | `MemLockScenariosTest` (wave-3 branch), `MemParkingLockScenariosTest` (parking branch) |
| CR-W5CC-7: `process.await-all-concurrent` drains once | `src/Scenarios/ConcurrencyScenarios.php` | `-` on mem; `SimulatedConcurrencyScenariosTest`, `SimulatedParkingConcurrencyScenariosTest` |
| CR-W5CC-7: `lock.parked-answer` (new) | `src/Scenarios/ParkedAnswerScenarios.php` | `MemParkedAnswerScenariosTest` |
| AW2: `process.resume-contention-keeps-answer` (new) | `src/Scenarios/ParkedAnswerScenarios.php` | `MemParkedAnswerScenariosTest` |
| AW1: `process.resume-cause` (new) | `src/Scenarios/ResumeCauseScenarios.php` | `MemResumeCauseScenariosTest` |
| E2: `effect.performed-not-recorded` (new) | `src/Scenarios/EffectStateScenarios.php` | `MemEffectStateScenariosTest` |
| W4: `workflow.item-deterministic-id` (new) | `src/Scenarios/WorkItemScenarios.php` | `MemWorkItemScenariosTest` |
| multi-consumer: `delivery.cross-consumer-once` (new, sf only) | `src/Scenarios/CrossConsumerScenarios.php` | `-` on mem; `SimulatedCrossConsumerScenariosTest` |

The parked-answer and resume-cause cases also run under `StartMode::Deferred` (`tests/Simulated/DeferredStart{ParkedAnswer,ResumeCause}ScenariosTest`).

## CR-W5C5-1: six wave-5 scenario ids and their cells

`ScenarioCatalogue::WAVES` and `CASES` gain six ids (47 → 53), each in a new abstract case, pinned by `CatalogueTest` (`WAVE_5`, "nothing is due after wave 5", "later-wave ids live in new cases" for waves 4 and 5).

| Id | mem | pdo | wp | sf | Case | Needs |
|---|---|---|---|---|---|---|
| `lock.parked-answer` | 5 | 5 | - | 5 | `ParkedAnswerScenarios` | ProcessHost, `wakeups()` implements `ICarriesFacts` |
| `process.resume-contention-keeps-answer` | 5 | 5 | - | 5 | `ParkedAnswerScenarios` | same |
| `process.resume-cause` | 5 | 5 | 5 | 5 | `ResumeCauseScenarios` | ProcessHost |
| `effect.performed-not-recorded` | 5 | 5 | - | 5 | `EffectStateScenarios` | EffectStateHost (CR-W5C5-2), `ITracksEffectState` journal |
| `workflow.item-deterministic-id` | 5 | 5 | 5 | 5 | `WorkItemScenarios` | WorkItemHost (CR-W5C5-3) |
| `delivery.cross-consumer-once` | - | - | - | 5 | `CrossConsumerScenarios` | CrossConsumerHost (CR-W5C5-4) |

Why the wp cells are what they are:

- `-` for the two AW2 ids: the wp wakeup store (`{prefix}_ddd_wakeups`, the AS projection keeps `['key' => …]`) has no fact column, and CR-W5CC-7 made it optional for wp.
- `-` for E2: wp has no effect journal (`effect.journal-reuse` is `-` on wp).
- `5` for AW1: the cause lives in the `steps` JSON every host persists whole (CR-W5CC-8 "Store contract: none"), so the wp adapters support it as they are.
- `5` for W4: the id comes from the core `WorkflowHandler`, and wp has both a behaviour-workflow store and a work-item ledger (`ddd-wp/src/Infra/Persistence/BehaviourWorkflowRepository`, `WorkItemRepository`). Only the conformance seam is missing on the wp fixture (request below).

Scenario summaries:

- **`lock.parked-answer`.** The process lock is held on another connection when the keyed answer arrives. The delivery is complete (the subscriber succeeded), exactly one live ResumeRetry intent carries the fact (`class`, `payload`, `event_id`; expected status `suspended`, the suspended step), nothing is saved. A redelivery is complete and still one intent. While the lock is held, the due wake is in `DrainReport::$wakes_retried`. Once it is free, the next due drain completes the wake and the answered step runs once, reading the parked fact's event id (AW1 on the parked path).
- **`process.resume-contention-keeps-answer`.** TXP's `ProcessLockContentionTest` "an answer held off longer than the delivery budget", inverted: the resume subscriber spends no ledger attempt; `IntegrationDelivery::DEFAULT_BUDGET + 1` redeliveries are all skipped; `WakeRetryPolicy::BUDGET + 2` drains at the cap all retry the wake; the ledger is never exhausted; the intent is an item of the operator view's `wakeup` layer (keyed by its idempotency key); after the lock frees the process resumes exactly once.
- **`process.resume-cause`.** `Fixtures\Process\ResumeCauseProcess` journals `resumed_by_event_id()` in every step: the keyed answer's step sees the answer's id; a partial AwaitAll runs nothing and the step after the gather sees the id of the fact that completed it (ruling: AwaitAll keeps the completing fact's id); the first step and the step after the gathered one see none; a `#[RetryStep]` re-run drained on worker 2 sees the same id (read from the row).
- **`effect.performed-not-recorded`.** Perform succeeds and record throws: the entry is `Performed`, `recorded_at` null, `performed_at` the host clock. Not an `effect` operator item yet; past `UnrecordedEffects::DEFAULT_AFTER_SECONDS` it is one (key = idempotency key, repairs `['invalidate']`, first seen = performed at), and `find_unrecorded()` lists it. The redelivery reuses the journaled result (no second perform) and records once; the entry is `Recorded` and leaves the layer. A dispatch under a new command id then neither performs nor records (`EffectLedger::$records`).
- **`workflow.item-deterministic-id`.** `Fixtures\Workflow\GrantWorkflow` (a core `WorkflowHandler`, behaviour `GrantConfig`, items `user:1`, `user:2`) sends `GrantAccess` per item through `HostFixture::command_bus()`; its handler commits `grant:{command id}` once per id. The worker dies after `user:2`'s command committed and before the ledger saved the item; the restart (workflow reloaded from the host store) re-runs `user:2` under the same `for_item()` id and adds no grant. The audit trail shows the ids `[user:1, user:2, user:2]`. A second workflow's items get other ids.
- **`delivery.cross-consumer-once`.** Consumer A raises `boom-1`, which A's own subscriber refuses forever. The copy routed to B is delivered to B's subscriber once; a redelivered copy is skipped; the pair is on B's ledger only. While A still retries its copy, A's next fact reaches B.

## CR-W5C5-2: `EffectStateHost`

```php
interface EffectStateHost extends EffectHost {
  public function operator_view(): IOperatorView;
}
```

The host's merged operator view (the same object as `ProcessHost::operator_view()` when a fixture implements both: the signatures are identical) with the core `UnrecordedEffects` source over `effect_journal()` at its default threshold and the host clock. `effect_journal()` must implement `ITracksEffectState` for the scenario to run; without the seam it skips with CR-W5C5-2, without the interface with CR-W5CC-6. A separate interface, because `EffectHost` is ratified (wave 4).

## CR-W5C5-3: `WorkItemHost`

```php
interface WorkItemHost {
  public function workflows(): IBehaviourWorkflowRepository;
  public function work_items(): IWorkItemRepository;
}
```

Not an extension of `WorkflowHost`, so a host without the D10 ignition ledger (wp) can provide it. A fixture implementing both serves one `workflows()`. The store must round-trip behaviour configs through `BaseBehaviourConfig`'s type registry; the scenario registers `GrantConfig::TYPE` with `register_type()`, which writes to the host registry when the host provides `IBehaviourTypes` (CR-W5CC-4), else to the fallback.

## CR-W5C5-4: `CrossConsumerHost`

```php
interface CrossConsumerHost {
  public function other_subscriptions(): ISubscriptionRegistry;
  public function other_ledger(): IDeliveryLedger;
  /** @return list<DeliveryOutcome> */
  public function deliver_routed(string $eventClass): array;
  public function deliver_other(string $eventClass, array $wrapped): DeliveryOutcome;
}
```

A second consumer in the same app. A subscriber added to `other_subscriptions()` makes that consumer an audience of the fact class, so `HostFixture::relay_once()` routes it a copy (sf: `FactAudience`, the copy addressed to its facts transport, CR-W5SF-2). `deliver_routed()` consumes what was routed to it and not yet delivered by this method; `deliver_other()` delivers one redelivered copy. `HostFixture::deliver_transported()` keeps delivering only the raiser's own messages to the raiser's subscribers. `Mem\MemCrossConsumerFixture` simulates it (group `simulated`, not a host result).

## CR-W5C5-5: mem fixture and helper additions

- `MemHostFixture::__construct(bool $shared_connection = false, StartMode $start_mode = StartMode::InBand, bool $parks_facts = false)`: the new trailing flag builds `InMemoryParkingScheduler` (CR-W5CC-7's opt-in). `MemSimulatedHostFixture` takes the same trailing flag.
- `MemHostFixture` implements `EffectStateHost` and `WorkItemHost`. Its effect journal reads the host clock (`new InMemoryEffectJournal($clock)`), its operator view lists `UnrecordedEffects` next to the ledger and wakeup sources, and `Mem\InMemoryWorkItemRepository` (serialized rows, enlisted) is its work-item ledger.
- `ProcessScenarioCase::parked(int $id, string $eventId)` (the live ResumeRetry intents carrying a fact) and `PAST_PARK_BACKOFF = 3` (past `WakeRetryPolicy::backoff_seconds(1)`, before any scenario alarm).
- `EffectLedger::$records`: successful `record()` calls per widget (reset with the ledger). `effect.journal-reuse` does not read it.

## Behaviour changes in existing scenarios

1. **`lock.acquire-error`, resume path, on an `ICarriesFacts` scheduler.** Asserts the delivery is complete, one ResumeRetry carries the fact for step 1 with expected status `suspended`, and nothing was saved; then the parked intent is woken through `ProcessWorker::runner()->wake($intent)` and the partial gather is saved (`version + 1`); a redelivery changes nothing. The CR proposed `advance_clock(PAST_WAKE_BACKOFF)` + `drain_once()` for the last step, but the scenario's timeout path has already made the gather's alarm due, so a drain would fire the FAIL timeout first; the direct wake is the same entry the timeout path uses (`handle_timeout()`). The full drain path is covered by `lock.parked-answer`. Without `ICarriesFacts`: unchanged.
2. **`process.await-all-concurrent`.** After the delivery retries, `advance_clock(PAST_PARK_BACKOFF)` and one `worker(1)->drain_once()`. On a host that parks the losing delivery, this resumes it; elsewhere the drain finds nothing due (the gather's 60 s alarm is not due at +3 s, and on success it was cancelled).

## Requests to other owners

### pdo (`packages/ddd-core/tests/Pdo/Conformance/**`, `packages/ddd-core/src/Defaults/Pdo/**`)

- `PdoCatalogueTest::WAVE = 5` and a `PDO_WAVE_5` list: `lock.parked-answer`, `process.resume-contention-keeps-answer`, `process.resume-cause`, `effect.performed-not-recorded`, `workflow.item-deterministic-id`.
- Native and Emulated host classes for `ParkedAnswerScenarios`, `ResumeCauseScenarios`, `EffectStateScenarios`, `WorkItemScenarios` (`hostClasses()` asserts them once WAVE is 5).
- `PdoHostFixture implements EffectStateHost, WorkItemHost` (`PdoBehaviourWorkflowRepository`, `PdoWorkItemRepository`; `PdoOperatorView` with `UnrecordedEffects`).
- The AW2 and E2 adapter work of CR-W5CC-6/7: a nullable `fact` column on `{prefix}_ddd_jobs` and `ICarriesFacts` on the pdo scheduler; `recorded_at` on `009`'s journal and `ITracksEffectState` on `PdoEffectJournal`. Until then the two AW2 ids skip with CR-W5CC-7 and E2 with CR-W5CC-6, which the pdo gate would report as not passing.

### sf (`packages/ddd-symfony/tests/Conformance/**`, `packages/ddd-symfony/src/**`)

- `SfCatalogueTest`: a wave-5 list with all six ids, and host classes `Sf{ParkedAnswer,ResumeCause,EffectState,WorkItem,CrossConsumer}ScenariosTest`.
- `SfHostFixture implements EffectStateHost, WorkItemHost, CrossConsumerHost`. For `CrossConsumerHost` the fixture needs a second consumer (as `MultiConsumerTest`'s `bil`): a subscriber added at run time to `other_subscriptions()` must make that consumer an audience of the class when `relay_once()` computes `FactAudience`s, and `deliver_routed()` consumes that consumer's facts transport.
- The adapter side of CR-W5CC-6/7 (CR-W5SF-R4): `DbalWakeupScheduler implements ICarriesFacts` with the `fact` column, `DbalEffectJournal implements ITracksEffectState` with `recorded_at`, `UnrecordedEffects` in the operator view.

### wp (`tests/Integration/Conformance/**`)

- `WpCatalogueConformance`: a wave-5 list, `process.resume-cause` and `workflow.item-deterministic-id`, and their host classes (`ResumeCauseScenarios`, `WorkItemScenarios`).
- `WpHostFixture implements WorkItemHost` over the wp `BehaviourWorkflowRepository` and `WorkItemRepository`.
- Not done here: wp-redelivery-default's optional item 3 (move the `ddd_conformance` delivery-attempts opt-in from `tests/Integration/bootstrap.php` into `WpConformanceRuntime`). Both files are outside this author's paths; the bootstrap opt-in keeps working.

### docs (next docs round)

- Register section 4: six rows with the cells above; section 8 wave 5: mem 5 ids, pdo 5, wp 2, sf 6. The count becomes 53.

## Verification on this branch

- `cd packages/ddd-conformance && rm -rf vendor composer.lock && composer install && vendor/bin/phpunit --group mem`: green, 0 skips (numbers in the round report).
- `vendor/bin/phpunit --group catalogue`, and the whole package suite (mem + simulated): green.
- pdo, unchanged code paths (MySQL 8, own per-test databases): `vendor/bin/phpunit -c packages/ddd-core/tests/Pdo/Conformance/phpunit.xml --filter 'PdoCatalogueTest|LockScenarios|ConcurrencyScenarios|EffectScenarios'`: OK (17 tests).
- sf, unchanged code paths (Postgres 16, database `ddd_w5_conformance5`): `vendor/bin/phpunit --filter 'SfCatalogueTest|SfLockScenariosTest|SfConcurrencyScenariosTest|SfEffectScenariosTest'` in `packages/ddd-symfony`: OK (12 tests).
