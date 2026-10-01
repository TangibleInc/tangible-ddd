# Wave 4: sf-conf4 change requests

Author: sf-conf4 (wave 4, round 3, symfony). Branch `wave4/sf-conf4`. Owned paths: `packages/ddd-symfony/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (3.8, 3.11, 4, 5.1, section 8 wave 4), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (CR-W4CE-1, -3, -5, -6, -9), [wave4-conformance-4-change-requests.md](wave4-conformance-4-change-requests.md) (W4C4-R3), [wave4-sf-a-change-requests.md](wave4-sf-a-change-requests.md), [wave4-sf-b-change-requests.md](wave4-sf-b-change-requests.md) (W4-SFB-R1).

No ratified core interface changed, and no file outside the owned paths was edited. `packages/ddd-symfony/composer.json` and the schema are unchanged (no new `schema/postgres` file, `released.txt` untouched). Public bundle config keys and service ids are unchanged; two config keys were added (CR sf-conf4-2).

## What this round did

| Request | Where | Test (Postgres 16) |
|---|---|---|
| CR-W4CE-9 / CR-PDO-6: store adopts `IReportsClaimDeadLetters`, relay stops double-signalling | `Persistence\DbalPostgresOutboxStore`, `Runtime\Relay` | `DbalPostgresOutboxStoreTest` (2 changed/new), `SfRelayScenariosTest` (`relay.lease-fencing` with the CR-PDO-6 assertion and `RecordsSignals`: exactly one `OutboxDeadLettered`) |
| CR-W4CE-3: `AttributeAuditPolicy` binding | `config/services.php`, `TangibleDddBundle` config | `Kernel/AuditPolicyWiringTest` (variant `audit`) |
| CR-W4CE-5: `LargeString` in process state, `quarantine_reason` | `Persistence\ProcessRowCodec` | `DbalProcessStoreTest` (3 new) |
| Fix found by `process.await-all-dynamic` | `ProcessRowCodec` decodes `steps` as arrays | `DbalProcessStoreTest::test_a_step_checkpoint_round_trips_and_is_readable_after_find` |
| CR-W4CE-1 / CR sf-a-5 (EffectMiddleware between act bracket and transaction with `DbalEffectJournal`) | already wired by sf-b (`tangible_ddd.middleware.effect`); now also the conformance `EffectHost` | `Kernel/ReferenceScenarioTest`, `SfEffectScenariosTest` |
| CR-W4CE-6 / L1 (`IReturningCommandHandler` autoconfiguration, `HandlerLocatorPass`) | already done by sf-b (CR sf-b-6); unchanged | `BundleWiringTest::test_a_returning_command_handler_is_autoconfigured_and_its_value_comes_back` |
| W4C4-R3: sf wave-4 cells | `tests/Conformance/SfHostFixture.php` seams `ProcessDecodeFaults`, `EffectHost`, `WorkflowHost`, `PostCommitWakeups`; 7 new host classes; `SfCatalogueTest` pins 9 ids | `Sf{Alarm,Await,Codec,Decode,Effect,Workflow,PostCommitWakeup}ScenariosTest`, `SfDecodeDeferredStartTest`, `SfCatalogueTest` |

sf wave-4 ids, all green on Postgres 16: `process.alarm-long`, `process.await-keyed-precheck`, `process.await-any-cancellation`, `process.await-all-dynamic`, `workflow.fact-ignition-once`, `codec.large-payload`, `decode.unknown-class`, `effect.journal-reuse`, `wakeup.post-commit`. Every wave-2/3 sf id stays green (conformance suite: 59 tests). Full package: 427 tests.

## CR sf-conf4-1 (CR-W4CE-9): `DbalPostgresOutboxStore implements IReportsClaimDeadLetters` (additive + behaviour change)

- The class now declares the core interface. `takeDeadLetteredAtClaim()` already had the shape. The class constant `LEASE_EXPIRED_ERROR` is no longer redeclared: it is inherited from the interface (same text), so `DbalPostgresOutboxStore::LEASE_EXPIRED_ERROR` still resolves.
- `Runtime\Relay::runOnce()` no longer takes and signals claim-time dead letters itself. The core relay step (`OutboxProcessor::claim_dead_letters()`) does it right after `claim()`: it logs `dlq`, emits `OutboxDeadLettered` once and lists the ids in `ProcessingResult::$deadLetteredAtClaim`. `RelayReport::of($r, $r->deadLetteredAtClaim)` keeps them in the report's `deadLettered` list, as before.
- Behaviour visible to a caller: the signal is the same class with the same entry, now dispatched by core (`OutboxProcessor`, with the relay's consumer config) and also logged at `dlq`. Before, sf emitted it outside the `try` in a `finally`; core takes the list before any submission can throw, so a throwing step still signals.

## CR sf-conf4-2 (CR-W4CE-3, D12): `tangible_ddd.audit.policy` is `AttributeAuditPolicy` (additive config, behaviour change)

- Service `tangible_ddd.audit.policy` (id unchanged) is `TangibleDDD\Runtime\Audit\AttributeAuditPolicy` with two new bundle keys, `audit.not_audited` and `audit.without_parameters` (lists of class names, parents or marker interfaces; default `[]`).
- `audit.policy: <service id>` still overrides the policy.
- Behaviour change: a command carrying `#[Audit(false)]` is no longer audited, and `#[Audit(parameters: false)]` drops its parameters. Commands without the attribute are audited exactly as before. To get the old literal behaviour, set `audit.policy` to a service of class `AuditEverything`.
- The conformance fixture's audited bus uses `AttributeAuditPolicy` too, to mirror the bundle. No conformance command carries the attribute.

## CR sf-conf4-3 (CR-W4CE-5, D6): LargeString business data in process rows (additive) and steps decoded as arrays (fix)

- `ProcessRowCodec::encode()` stores a promoted constructor parameter that holds a `LargeString` as `LargeString::toPayload()` (base64, length, sha256, max_bytes). `decode()` revives a parameter typed `LargeString` (nullable or not) with `LargeString::fromPayload()`. An `UndecodableLargeString` becomes the quarantine: status `failed`, `quarantine_reason` = `"{class} cannot be rebuilt: {quarantineReason}"`, then `QuarantinedProcess` (R5). This is the pdo `ProcessCodec` behaviour.
- Fix: `steps` is now decoded with `json_decode(..., true)`. With `stdClass` checkpoints, `ProcessSteps::checkpoint_for()` passed a `stdClass` to `JsonLifecycleValue::deserialize_polymorphic(?array)` and threw a `TypeError` on the first step that read its checkpoint after a reload (`process.await-all-dynamic`, `children_first` → `assemble`). `ProcessSteps::from_json_instance()` casts every field with `(array)`, so the array form is equivalent for everything else.
- No schema change: `business_data` is `TEXT`. A 1 MiB binary value (about 1.4 MB of base64) round-trips.
- Not covered: a `LargeString` nested inside a process payload object or a checkpoint (`JsonLifecycleValue`) is not scanned. That matches pdo.

## CR sf-conf4-4: conformance fixture seams (test code only)

- `SfHostFixture` implements `ProcessDecodeFaults`, `EffectHost`, `WorkflowHost` and `PostCommitWakeups`.
  - `effectBus()` is the bundle bus with `EffectMiddleware(DbalEffectJournal, boundary)` between the act bracket and the transaction. The handler map routes `RecordEffect` to `apply()`, because the fixture's terminal is a handler map, not SelfExecuting. `commandBus()` keeps the frozen order with no effect stage.
  - `WorkflowHost` wires the `tangible_ddd.workflow_*` services as the bundle does: the ledger on the app clock, `WorkflowIgniter(ledger, boundary, logger, clock)`.
  - `PostCommitWakeups`: every worker's outbox store NOTIFYs on append, as with the bundle default `relay.listen: true`. Worker 1 does it through a one-shot suppressible decorator. The relay worker is worker 9, on its own connection. It runs `RelayCommand`'s loop rule in steps (a pass; when the pass claimed nothing, `PostgresListenWaiter::wait()` up to the poll interval measured from going idle), with a 3 s poll. The separate-process `ddd:relay` evidence is still `Kernel/PostCommitWakeupTest` and `PostCommitPollFallbackTest`.
- New optional constructor parameter `SfHostFixture(StartMode $startMode = StartMode::Deferred)`. `SfDecodeScenariosTest` uses `StartMode::InBand`, a legal bundle configuration (`process.inband_start: true`) (see W4-SFC4-R2). `SfDecodeDeferredStartTest` makes the same claims under the default deferred start.

## Requests to other owners

- **W4-SFC4-R1 (core-process, `Application/Process/ProcessSteps`; affects pdo and wp).** `ProcessSteps::from_json_instance()` normalises `resume` from `stdClass`, but not `checkpoints`. Every host that decodes `steps` with `json_decode($json, false)` hands `checkpoint_for()` a `stdClass` and gets a `TypeError` once a step reads its checkpoint after a reload. Both pdo `Internal\ProcessCodec` (line 74) and wp `ProcessRepository` (line 184) do this. `process.await-all-dynamic` hits it on the first resumed step. Suggested additive fix: in `from_json_instance()`, `checkpoints: array_map(fn ($c) => json_decode((string) json_encode($c), true), (array) ($data['checkpoints'] ?? []))`, as for `resume`. sf no longer depends on it (CR sf-conf4-3).
- **W4-SFC4-R2 (conformance).** `DecodeScenarios::test_decode_unknown_class` is not start-mode neutral. It starts two `HopWidgetProcess`es one after the other through `ProcessScenarioCase::start()`, then asserts `['first', 'first']`. On a deferred host, the second start's drain also runs the first process's due `#[Async]` `second` step, which gives `['first', 'second', 'first']`. Suggested: start both with `processRunner()->start()`, drain once, and then assert (what `SfDecodeDeferredStartTest` does). sf then runs the shared case under its default `StartMode::Deferred`.
- **W4-SFC4-R3 (coordinator).** Ratify CR sf-conf4-1..3. Record that sf now satisfies the CR-PDO-6 rule through the core interface (W4C4-R3 done).

## Compatibility impact

- **Behaviour changes:**
  - `#[Audit]` is honoured by the default policy (CR sf-conf4-2).
  - The claim-time `OutboxDeadLettered` is emitted by core, once, and is also logged (CR sf-conf4-1).
  - A process with a `LargeString` constructor parameter can be stored and loaded. Before, encoding threw `ProcessStoreFailed` on binary, or loading failed with a `TypeError`-based quarantine.
  - Stored rows are unchanged for every existing process: `business_data` changes only for `LargeString` values, and `steps` JSON is written as before.
- **Public surface:**
  - `DbalPostgresOutboxStore` gains an interface, and its constant is now inherited.
  - The bundle gains `audit.not_audited` and `audit.without_parameters`.
  - No service id, alias, tag, console command, schema file or composer constraint changed.
- **TXP process-kernel slice:** the bundle config it uses is unchanged. A TXP command marked `#[Audit(false)]` now loses its audit row, as D12 intends.

## Open (not change requests)

- W4C4-R6 (core-process) is still open. sf uses the core runner, so a due wake for a quarantined process should behave as it does on mem. This was not asserted here: the drain passes report no errors, which is all the register requires.
- `DbalProcessStore::findStranded()` still does not probe the advisory lock (sf-a and sf-b open item).
- `HandlerLocatorPass`'s exclusion of an `IReturningCommandHandler`-typed `handle()` parameter has no unit test of its own. The kernel test covers autoconfiguration.
