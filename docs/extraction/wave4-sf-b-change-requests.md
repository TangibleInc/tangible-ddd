# Wave 4: sf-b change requests

Author: sf-b (wave 4, symfony part B, after core-process merged). Branch `wave4/sf-b`. Owned paths: `packages/ddd-symfony/**`, `examples/symfony/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (3.6-3.8, 3.11, 4, 5.3, section 8 wave 4), [wave3-notes.md](wave3-notes.md) (L1, WP8-10), [wave4-core-process-change-requests.md](wave4-core-process-change-requests.md) (W4P-R1, W4P-R6), [wave4-sf-a-change-requests.md](wave4-sf-a-change-requests.md) (CR sf-a-3 README request, CR sf-a-5 EffectMiddleware wiring request), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (L1 request to sf), E section 10.

No ratified core interface changed and nothing outside the owned paths was edited. `packages/ddd-symfony/composer.json` is unchanged. There is no schema change: `ddd_process_waits` (006) already had `(process_id, event_class, await_key)` as its key, so `released.txt` is untouched and no `010_*` file was needed.

## What this round did

| Demand | Where | Test (Postgres 16) |
|---|---|---|
| D3 any-of, keyed, dynamic AwaitAll (W4P-R1.1, W4P-R6) | `DbalProcessStore::writeWaits()` writes one `ddd_process_waits` row per `LongProcess::await_routes()` route; `DbalProcessStore implements IMatchesFactAncestry` | `DbalProcessStoreTest` (5 new), `Integration/Process/ProcessRunnerWave4PostgresTest` (core runner on the sf adapters: keyed fan-out, unheard key, keyed timeout, any-of answer and cancellation, precheck hit and miss, dynamic AwaitAll shrinking / empty / across a worker restart) |
| D7 long alarms | no code change needed: the core runner's absolute `due_at` lands in `ddd_wakeups.due_at` (TIMESTAMPTZ) | `ProcessRunnerWave4PostgresTest`: a 25 h alarm is one intent at the exact UTC instant, not due at 24h59m59s, fires once after a worker restart, a 48 h overshoot and a duplicate timeout wake are no-ops; an `AwaitAlarm::at()` in +02:00 is stored as the same UTC instant; a partial AwaitAll arrival does not re-delay the alarm |
| D10 (W4P-R1.2, W4P-R1.3) | `DbalWorkflowIgnitionLedger implements IWorkflowIgnitionLedger`; service `tangible_ddd.workflow_igniter` (core `WorkflowIgniter`); tag `DddTags::WORKFLOW` autoconfigured for `IStartsFromFact`; `SubscriptionMapPass` + `CompiledSubscriptionRegistry::workflowSpec()` | `Kernel/WorkflowIgnitionTest` (`workflow.fact-ignition-once` through outbox, `ddd:relay` and `messenger:consume`), `DbalWorkflowStoresTest` |
| D14 | no code change: verified end to end | `Kernel/PostCommitWakeupTest` (a separate `ddd:relay` php process idle in LISTEN, 3 s poll: a committed fact relayed < 1 s, a committed wakeup intent projected < 1 s, a rollback sends no NOTIFY and writes no row), `Kernel/PostCommitPollFallbackTest` (variant `no_listen`: relayed within one poll interval) |
| D1 wiring (CR sf-a-5) | `tangible_ddd.middleware.effect` = core `EffectMiddleware(DbalEffectJournal, boundary)` between the act bracket and the transaction | `Kernel/ReferenceScenarioTest` |
| E section 10 | the reference scenario as a kernel test | `Kernel/ReferenceScenarioTest` (below) |
| D13 | documentation | `examples/symfony/README.md` section 9; `ReferenceScenarioTest` asserts the step command id and the minted job id |
| WP8-10 fix | `Ops\CoreStrandedRepairs` names core's real classes; `Runtime\ExplicitHandlerMapping` | `CoreStrandedRepairsTest`, `ProcessWiringTest::test_ops_stranded_repairs_run_through_the_bundle_wiring` |
| L1 | `IReturningCommandHandler` autoconfigured with the command-handler tag; `HandlerLocatorPass` | `BundleWiringTest::test_a_returning_command_handler_is_autoconfigured_and_its_value_comes_back` |
| docs | `examples/symfony/README.md` (sections 5-10: workers, D1, D3/D7, D10, D13, tests), package README wave-4 section, CR sf-a-3 comment fixes | - |

**Reference scenario** (`ReferenceScenarioTest`, kernel variant `frozen_clock`): `ToyProvision` `#[StartsOn(ToyRequested)]` is ignited once although the fact is delivered twice; step `order` mints its job id with `step_ref('job')`, awaits `ToyJobFinished` keyed on it with a 30 min alarm (one `timeout` intent at now + 1800 s), and dispatches `OrderToyJob` after the await committed, under the deterministic step command id; the process survives a kernel restart; the keyed answer resumes `charge`, a `#[RetryStep(attempts: 1)]` step whose `ChargeToyCommand` (`IExternalEffectCommand`, key `step_ref('charge')`) performs once, fails in `record()`, and on the step's re-run records with the journaled result (one perform, one journal row, one `ToyCharged` outbox row from the successful record only); a late duplicate timeout is stale; a failed job retries the step once and then compensates `order` (`CancelToyJob`), with nothing charged; an unanswered saga is failed by the alarm at exactly 30 min and a late answer is unheard. The remaining E section 10 variants are already sf conformance cells (listed in the test's docblock).

## CR sf-b-1 (D10): `DbalWorkflowIgnitionLedger` is the core port (sf class signature change)

- `implements IWorkflowIgnitionLedger`. **`find()` now returns `?WorkflowIgnition` instead of `?array{kind, workflow_id, event_id, created_at}`.** This is the change core-process requested (W4P-R1.2). It is not additive for a caller of the sf class's `find()`: the array keys become the value object's properties (`kind`, `workflowId`, `eventId`, `createdAt`, plus `dedupKey`). The class was sf-local since wave 3, has never been released, and no caller exists outside `packages/ddd-symfony` (grep over the repo and `/Users/titustc/tgbl/txp-slices`, read-only). It is not a ratified core interface. **Coordinator: please ratify** (the alternative, a second method, would leave the class unable to implement the port).
- Additive: optional trailing constructor parameter `?IClock $clock = null`. When given, `claim()` writes `created_at` from it, so `WorkflowIgniter`'s stale-claim and stale-start-marker ages (it compares `createdAt` with its own clock) use the app clock instead of the database's `now()`. The bundle passes `tangible_ddd.clock`.
- `keyForFact()` now delegates to `WorkflowIgnitionKey::forFact()` (same value, uuid5(event_id, kind)).

## CR sf-b-2 (D10): workflow ignition in the compiled subscription map (additive)

- `DddTags::WORKFLOW = 'tangible_ddd.workflow'`, autoconfigured for `IStartsFromFact`.
- `SubscriptionMapPass` compiles one spec per `#[StartsOn]` fact of a tagged service (`CompiledSubscriptionRegistry::workflowSpec($serviceId, $class, $fact, $prefix)`, kind `workflow`, priority `Subscriber::IGNITION`) and puts the service in the listener locator; compilation fails for a tagged class that does not implement `IStartsFromFact`, declares no `#[StartsOn]`, or names a non-fact.
- `CompiledSubscriptionRegistry::__construct()` gains the optional trailing `?WorkflowIgniter $workflows = null`. A workflow spec is built through `WorkflowIgniter::register()` into a capturing registry with the lazily located service, so the subscriber id is the igniter's own `{prefix}/workflow-ignition:{class}@{fact}` and the delivery-ledger keys agree with every host. A workflow spec without an igniter throws `\LogicException` at delivery.
- Services: `tangible_ddd.workflow_igniter` (alias `WorkflowIgniter`) = `WorkflowIgniter(ledger, transaction boundary, logger, clock)`; alias `IWorkflowIgnitionLedger` → `tangible_ddd.workflow_ignitions`.

## CR sf-b-3 (D3): route-indexed `ddd_process_waits`; `IMatchesFactAncestry` (additive)

- One row per `await_routes()` route instead of one `(waiting_for, '')` row. A suspended row without a mechanism (an old 0.6-shaped row) keeps its `waiting_for` row. An `AwaitAlarm` writes none.
- `DbalProcessStore implements IMatchesFactAncestry`: its lookup already matched parents and interfaces (wave 3, for D2 marker awaits), so the runner makes one lookup per fact. This keeps sf's wave-3 behaviour that a 0.6-shaped await on a parent class or marker interface is reached by a subclass fact (W4P-R6: "sf declares it only if its lookup matches ancestors").

## CR sf-b-4 (D1): `EffectMiddleware` in the bundle's command bus (behaviour change)

- New service `tangible_ddd.middleware.effect`; the bus is act bracket → effect → transaction → domain events → self-executing → handler (the register's frozen order with D1 inserted where 3.11 puts it). Commands that are not `IExternalEffectCommand` pass through untouched.
- Behaviour change: an `IExternalEffectCommand` dispatched on sf is now journaled and split into `perform()` (outside any transaction, refused with `EffectInsideTransaction` inside one) and `RecordEffect` (inside the transaction). Before, it went to its handler like any command.

## CR sf-b-5 (WP8-10): `ddd:ops:stranded` reaches core's repair commands (fix + behaviour change)

- `CoreStrandedRepairs::RESUME` / `FAIL` were `Application\Process\{Resume,Fail}StrandedProcess`; core shipped `Application\Process\Repair\…` with `(consumer_prefix, process_id, …)`. After the merge `available()` was false and the inline repair kept running. Now the constants name the real classes, arguments are bound by name (`consumer_prefix`, `process_id` / `processId` / first parameter, `reason`), and an optional trailing `string $consumerPrefix = ''` constructor parameter carries the consumer.
- `Repair` is not a `Commands` namespace, so the naming convention cannot route it. New `@internal` `Runtime\ExplicitHandlerMapping` (explicit map, then `MapByNamingConvention`) is the `tangible_ddd.handler_mapping` service; the bundle fills parameter `tangible_ddd.explicit_handlers` with core's `XHandler` next to `X` and registers those handlers (autowired over the bundle's port aliases, tagged).
- Behaviour change: `--resume` / `--fail` now apply core's guards. A process that is not in `findStranded()` (for example `running` but touched within `stranded_after_seconds`), or whose lock is held, is refused (`ProcessNotStranded`, exit 1). The inline repair accepted any non-final process. `--resume` of a `running` row writes a `resume_retry` intent (core) instead of a `continue` one.

## CR sf-b-6 (L1) and LongProcess autoconfiguration (additive)

- `IReturningCommandHandler` is autoconfigured with `DddTags::COMMAND_HANDLER` and treated like `ICommandHandler` by `HandlerLocatorPass`'s `handle()` type scan (core-effects request).
- `LongProcess` subclasses registered as services are autoconfigured with `ddd.long_process`, so an app's resource loading is enough for `#[StartsOn]` / `#[Awaits]` to be compiled. Their (usually unautowirable) definitions are unused and removed, so the deferred autowiring errors never surface. An app that also tags them by hand gets the tag twice; `LongProcessCatalogPass` then lists the class's tag entries twice (harmless).

## Requests to other owners

- **W4-SFB-R1 (conformance-4).** The sf wave-4 cells: `process.alarm-long` and `workflow.fact-ignition-once` can be wired on `SfHostFixture` as they are (the fixture's runner and stores are the sf adapters; for D10 build `WorkflowIgniter` over `DbalWorkflowIgnitionLedger($connection, '', $clock)`). `effect.journal-reuse` needs the fixture's command bus to carry `EffectMiddleware(DbalEffectJournal)` between `CorrelationMiddleware` and `TransactionalCommandMiddleware`, and `HandlerMapMiddleware` must route `RecordEffect` to `RecordEffect::apply()` (it is a SelfHandlingCommand); tell sf which `BusOptions` seam you add and sf wires it. `wakeup.post-commit` needs a relay worker in a separate process; the sf evidence is `PostCommitWakeupTest` / `PostCommitPollFallbackTest` (`tests/Kernel/bin/relay-loop.php` can be reused by the fixture).
- **W4-SFB-R2 (coordinator).** Ratify CR sf-b-1 (`find()` return type) and CR sf-b-5's stricter repair guards.

## Compatibility impact

- No schema change, no core change, no composer change. Existing `ddd_process_waits` rows of 0.6/wave-3 mechanisms are identical to what this branch writes (one `(event_class, '')` row); rows are rewritten on every save anyway.
- sf-class changes callers can see: `DbalWorkflowIgnitionLedger::find()` return type (CR sf-b-1), the `tangible_ddd.handler_mapping` service class, stricter `ddd:ops:stranded` repairs, `IExternalEffectCommand` now journaled. Everything else is additive (new optional trailing constructor parameters, new services, tags and classes).
- Rollback (register 7.3) is unaffected by this branch's code; the core-process rollback note for rows suspended on the new mechanisms applies unchanged.

## Open (not change requests)

- D6 on sf (`codec.large-payload`, `decode.unknown-class`: `ProcessRowCodec` reviving `LargeString` fields and mapping `UndecodableLargeString::$quarantineReason`) was not in this author's task and is not done here.
- `DbalProcessStore::findStranded()` still does not probe the advisory lock (sf-a open item); core's repair refuses a held lock, so the operator path is safe, but `ddd:ops:list` can show a long-running wake as stranded.
