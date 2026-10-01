# Wave 5: hosts-follow change requests

Author: hosts-follow (wave 5, rounds 2-3). Branch `wave5/hosts-follow`, based on `extraction/ddd-packages` at `aae89ab` (round 1 merged). Owned paths: `packages/ddd-core/src/Defaults/Pdo/**`, `packages/ddd-core/schema/mysql8/**`, `packages/ddd-core/tests/Pdo/**` except `Conformance/**`, `examples/plain-php-durable/**`, `packages/ddd-wp/**`, `tests/Integration/**` except `Conformance/**`, `packages/ddd-symfony/{src/Persistence,schema/postgres,config,src/Bundle,tests}/**` except `tests/Conformance/**`.

The task: the three hosts follow the core requests CR-W5CC-1..8 (wave5-core-correctness-change-requests.md) with additive schema changes only, plus the ddd-wp fresh-database wiring bug from the cred consumer triage.

Every item is additive: a new class, a new interface on an existing class, an optional trailing argument, a new numbered schema file, a new wp schema version. No ratified interface lost or changed a method. The 0.6 frozen API (R1-R5) is untouched; wp schema v9 adds one nullable column that a 0.6 winner never reads.

## What this round did

| CR | sf (primary) | pdo | wp |
|---|---|---|---|
| CR-W5CC-1 ConflictException | `PersistenceConflict extends ConflictException` | - | - |
| CR-W5CC-4 IBehaviourTypes | `tangible_ddd.behaviour_types` service, provided at boot, `hand_over_types()` | `DurableRuntime` provides or reuses one, `hand_over_types()`, `behaviour_types()` | not done (optional; the static facade keeps working) |
| CR-W5CC-5 handler-class effects | autoconfiguration + `EffectHandlersPass` (locator, mapping) | `Internal\EffectHandlers` (array form or convention) | no effect journal: skipped |
| CR-W5CC-6 entry states, `effect` layer | schema 011 `recorded_at`, `DbalEffectJournal implements ITracksEffectState`, `UnrecordedEffects` source, `ddd:ops:effects:invalidate` | schema 010 `ddd_effect_recorded`, `PdoEffectJournal implements ITracksEffectState`, `UnrecordedEffects` source, repair `invalidate` | no effect journal: skipped |
| CR-W5CC-7 parked fact resume | schema 011 `ddd_wakeups.fact`, `DbalParkingScheduler`, `ParkedFacts` | schema 011 `ddd_job_facts`, `PdoParkingJobStore` | schema v9 `ddd_wakeups.fact`, `WpdbParkingScheduler` |
| cred triage: fresh database | - | - | `TABLES_INSTALLED_ACTION`, probe cache in `WpSchema` |

## CR-W5HF-1: the parked fact on each host (AW2, CR-W5CC-7)

- **Why a subclass.** Conformance `lock.acquire-error` still pins the wave-3 rule ("the resume subscriber failed; its delivery is retried"); CR-W5CC-7 lists the scenario change as needed "before any host declares ICarriesFacts", and it is not on the integration branch. Core solved the same problem on mem with `InMemoryParkingScheduler`. Each host does the same: the scheduler class stores and returns the fact, and a final subclass declares `ICarriesFacts`. The production wiring uses the subclass; the host conformance fixtures, which build the base classes directly, keep passing the wave-3 assertions. When the conformance owner branches `lock.acquire-error` on `ICarriesFacts`, the fixtures can switch to the subclasses (`SfWorkerPorts`, `PdoHostFixture`, `WpConformanceRuntime`; not owned here).
- **New classes (non-final base classes).** `DbalWakeupScheduler`, `PdoJobStore` and `WpdbWakeupScheduler` are no longer `final`, so the subclasses can extend them:
  - sf `TangibleDDD\Symfony\Persistence\DbalParkingScheduler`, every consumer's `wakeup_scheduler` service;
  - pdo `TangibleDDD\Defaults\Pdo\PdoParkingJobStore`, what `DurableRuntime::compose()` builds (`jobs()` still returns a `PdoJobStore`);
  - wp `TangibleDDD\WordPress\Adapter\WpdbParkingScheduler`, what `WpHostPortFactory` serves to a consumer at schema v9 (v8 keeps `WpdbWakeupScheduler`).
- **Storage.**
  - sf: schema `011_effect_states_and_facts.sql`, `ALTER TABLE ddd_wakeups ADD COLUMN IF NOT EXISTS fact JSON NULL` (JSON, not JSONB, so the payload's key order round-trips). The INSERT names `fact` only for an intent that carries one, so a host at schema 010 keeps scheduling every other intent. `DbalWakeupScheduler::find(string $key): ?WakeupIntent` is new (sf method, not on the port).
  - pdo: schema `011_job_facts.sql`, a side table `{prefix}ddd_job_facts (idempotency_key PK, fact LONGTEXT)`. MySQL 8 has no `ADD COLUMN IF NOT EXISTS`, and the pdo rule (SchemaReleasedTest) is that new state goes in a new table; this is the pdo form of "a nullable JSON column on the wakeup rows". `schedule()` writes it in the job's transaction (only for a newly inserted job), `claim_due()` joins it, `complete()` and `cancel()` delete it with the job (`DELETE j, f ... LEFT JOIN`).
  - wp: schema v9, `{prefix}_ddd_wakeups.fact LONGTEXT NULL` (dbDelta in `install_wakeups_table()` and the explicit v9 migration). Written only for a fact-carrying intent; `intent()` reads `$row->fact ?? null`, so `begin_key()` (the `{prefix}_ddd_wakeup` callback) and `claim_due()` return it. The Action Scheduler projection stays `['key' => …]`.
- **sf: `ParkedFacts`.** The Messenger projection of an intent, `ProcessWakeupMessage`, has no fact field, so the intent `ProcessWakeupHandler` rebuilds has `fact` null. Woken like that, a ResumeRetry with expected status `suspended` is the repeat of an await TIMEOUT (`ProcessRunner::resume_retry()`). `TangibleDDD\Symfony\Persistence\ParkedFacts implements IProcessWakeTarget` wraps each consumer's `process_wake_target` and reads the fact back from the row by key; a key in the `resume_fact()` format whose row has no fact is not woken at all. See API request R1 for the cleaner fix in the message.
- **Tests.** sf `tests/Kernel/ParkedAnswerTest` (the inversion of TXP's `ProcessLockContentionTest`: answer parked while another session holds the advisory lock, delivery acked with no ledger attempt, wake retried while the lock is held, process resumed after; a fact-less parked key is a no-op, not a timeout), `DbalWakeupSchedulerTest` (round trip, key order, `find()`, the marker). pdo `DurableRuntimeWave5Cases` (the same scenario through `compose()` and `drain()` on a held MySQL named lock), `PdoJobStoreCases` (round trip, rollback with the intent, delete with complete/cancel, one job per parked fact). wp `tests/Integration/V8/WpParkedFactV9Test` (migration to v9 from fresh and from a v8 table, the factory, round trip, a v8 table without the column, the parked answer through `do_action()` and the `{prefix}_ddd_wakeup` Action Scheduler action).

## CR-W5HF-2: effect entry states and the `effect` layer (E2, CR-W5CC-6)

- **sf.** Schema 011: `ddd_effect_journal.recorded_at TIMESTAMPTZ NULL` and a partial index `(performed_at) WHERE recorded_at IS NULL AND invalidated_at IS NULL`. `DbalEffectJournal implements ITracksEffectState`; `store()`'s upsert resets `recorded_at`; `mark_recorded()` skips invalidated rows; `find_entry()` / `find_unrecorded()` skip invalidated rows, `find_unrecorded()` orders by `performed_at, idempotency_key`. `ITracksEffectState` is aliased to `tangible_ddd.effect_journal`. `UnrecordedEffects($journal, $prefix, $clock)` is a source of every consumer's `PortOperatorView`, so `ddd:ops:list --layer=effect` works.
- **sf `ddd:ops:effects:invalidate <key>... [--reason=]`** (`TangibleDDD\Symfony\Console\Ops\EffectsInvalidateCommand`): each key in its own transaction on the primary consumer's boundary; exit 1 when a key has no live entry or fails. **This file is outside the owned paths** (`src/Console/**`); it is a new file, so it cannot conflict, but the coordinator should ratify its location. See R2.
- **pdo.** Schema 010: side table `{prefix}ddd_effect_recorded (idempotency_key PK, recorded_at)`, for the same reason as `ddd_job_facts`. `PdoEffectJournal implements ITracksEffectState`; `store()` deletes the recorded row and upserts the entry in one transaction (its own when none is open); `mark_recorded()` is an `INSERT ... SELECT` from the live entry. `PdoOperatorView` gets the `UnrecordedEffects` source and the repair `effect` / `invalidate` (`OperatorRepairs`, option `reason`, `PdoRepairRefused` without a live entry). `SchemaSql::TABLES` lists the two new tables.
- **Entries stored before the new schema** have no recorded mark and list as unrecorded once older than 300 s. Nothing was released, so only development databases have them; the schema file comments say so.
- **Behaviour change (as CR-W5CC-6 announced).** `DurableRuntimeWave4Cases::test_a_repair_that_invalidates_in_its_transaction_makes_the_effect_perform_again` now expects `['cus_1', 'cus_2']` (a Recorded entry is not recorded again).

## CR-W5HF-3: handler-class effects (E1, CR-W5CC-5)

- **sf.** `IExternalEffectHandler` is autoconfigured with `tangible_ddd.command_handler`, so `HandlerLocatorPass` puts it in the command handler locator (keyed by class). New `TangibleDDD\Symfony\Bundle\EffectHandlersPass` (`@internal`, after `HandlerLocatorPass`) copies that locator into argument 2 of every consumer's `middleware.effect`; argument 3 is `tangible_ddd.handler_mapping`. The pass lives in `src/Bundle` because `src/DependencyInjection/**` is not owned; it can move into `HandlerLocatorPass` (R3).
- **pdo.** `Internal\EffectHandlers` is both the locator and the mapping `EffectMiddleware` takes: the array-form `$handlers` entry keyed by the effect command's class, else the convention-named handler in the runtime container. No handler: `NoEffectHandler` before anything is performed (tested).
- **Tests.** sf `tests/Kernel/EffectWiringTest` (perform/record by the handler, a Recorded entry not recorded again, a failed record leaves Performed and the retry records without performing, the `effect` layer in the view and `ddd:ops:list`, `ddd:ops:effects:invalidate` and its unknown-key failure). pdo `DurableRuntimeWave5Cases`.

## CR-W5HF-4: the behaviour type registry (W2, CR-W5CC-4)

- **sf.** `tangible_ddd.behaviour_types` (public, `BehaviourTypes` built from the compiled map parameter of the same name; alias `IBehaviourTypes`). `TangibleDddBundle::boot()` provides it to `HostDefaults` and calls `BaseBehaviourConfig::hand_over_types()`. `HostDefaultsInstaller` (not owned) still registers the compiled types through the facade, which now writes into the provided registry; harmless and idempotent.
- **sf, reboot carry-over (additive).** `hand_over_types()` drops the include-time fallback after the first hand-over. A second kernel in the same process (KernelTestCase boots the previous kernel once more to shut it down; a worker's kernel reset) would lose those types, so `boot()` also copies the entries of the previously provided `BehaviourTypes` that the new one lacks. Tested in `tests/Kernel/BehaviourTypesTest`.
- **pdo.** `compose()` reuses `HostDefaults`' `IBehaviourTypes` or provides a new `BehaviourTypes`, then `hand_over_types()`. `DurableRuntime::behaviour_types()` is new; the container serves `IBehaviourTypes`. Two runtimes in one process share the registry.
- **wp.** Not done (CR-W5CC-4 calls it optional for wp; `register_type()` keeps working through the fallback).

## CR-W5HF-5: ddd-wp fresh-database wiring (cred consumer triage)

- **Bug.** `register_hooks()` (init:2) probed `processes_enabled()` / `outbox_enabled()`, whose function-static caches remembered "absent" for the request, before `ddd_maybe_migrate()` created the tables at init:3. On a fresh install the first request wired no `#[StartsOn]` / `#[Awaits]` hook and no outbox hook.
- **Fix.** The probe cache moved to `WpSchema::reachable(string $table, callable $probe): bool` with `WpSchema::forget_tables(): void` (the procedural signatures are unchanged). `ddd_maybe_migrate()` forgets the probes right after `install_tables()` and fires the new action `TangibleDDD\WordPress\TABLES_INSTALLED_ACTION` (`'tangible_ddd_tables_installed'`, argument: the consumer's `IDDDConfig`). `register_hooks()` wires the gated parts in a closure; whatever was closed at init:2 is wired when its consumer's tables are announced, once, in the same request. I chose this over calling `ddd_maybe_migrate()` from `register_hooks()` because the unit tests run `register_hooks()` against stubs without `dbDelta` or `ABSPATH`, and because the action also covers a migration that runs later (admin_init, WP-CLI).
- **Tests.** `tests/Integration/V8/FreshDatabaseWiringTest`: an empty database, `register_hooks()` wires nothing, `ddd_maybe_migrate()` opens the gates and wires the ignition, and the first fact ignites the process in the same request; re-announcing wires nothing twice; another consumer's tables wire nothing; installed tables wire at once with no listener left.
- **Also new.** `WpSchema::V9`, `WpSchema::is_v9()`, `DDD_SCHEMA_VERSION = 9`, the v9 explicit migration, `V8TestCase::installCurrent()`.

## Edits outside the owned paths (for ratification)

These are mechanical consequences of the task, each in its own commit:

1. `tests/Unit/Abi/fixtures/procedural/current.json`, re-frozen with `php tests/Unit/Abi/bin/generate-fixtures.php --current`: `DDD_SCHEMA_VERSION` 8 → 9 and the new constant `TABLES_INSTALLED_ACTION`. No procedural function changed.
2. `tests/Unit/WordPress/MigrationsTest.php`: `test_current_schema_version_is_8` → `_is_9` (the guard exists to move with every schema bump), plus `test_v9_migration_has_an_explicit_entry`.
3. `packages/ddd-symfony/src/Console/Ops/EffectsInvalidateCommand.php`: new file (R2).

## API change requests to other owners

- **R1 (sf Messenger owner).** `ProcessWakeupMessage` could carry the fact: an optional trailing `?array $fact = null` constructor argument, set by `from_claim()` and passed on by `to_claim()`. `ParkedFacts` would then be a fallback for messages serialized before the change, not the main path. Not required for correctness.
- **R2 (sf Console owner).** Ratify or move `EffectsInvalidateCommand` (`ddd:ops:effects:invalidate`). It takes `IEffectJournal` and `ITransactionBoundary`, like the other `ddd:ops:*` commands act on the primary consumer; a `--consumer` option can follow the multi-consumer pattern later.
- **R3 (sf DependencyInjection owner).** Optionally fold `EffectHandlersPass` into `HandlerLocatorPass` (one more `locate()` target per consumer's `middleware.effect`, argument 2).
- **R4 (conformance owner).** CR-W5CC-7's proposed `lock.acquire-error` branch on `ICarriesFacts`, `lock.parked-answer` and the extra drain in `process.await-all-concurrent`. Once they land, the host fixtures switch to `DbalParkingScheduler` / `PdoParkingJobStore` / `WpdbParkingScheduler` (fixture files not owned here) and the parked path is covered by conformance on every host.
- **R5 (packaging/docs owner).** The sf and wp guides and the CHANGELOG: schema 010/011 (pdo), 011 (sf), wp v9, `ddd:ops:effects:invalidate`, the `effect` layer, `TABLES_INSTALLED_ACTION`, and that `PersistenceConflict` is no longer a `\RuntimeException`.
- **R6 (ddd-wp, optional, later).** `WpOperatorView::LAYERS` has no `effect` layer because wp has no effect journal; nothing to add until wp gets one.

## Compatibility

- Every schema change is a new numbered file (pdo 010, 011; sf 011) or a new wp version (v9), with its `released.txt` line. Hosts apply them with `SchemaSql::dump($prefix, 9)` / `ddd:schema:dump --since=10` / the wp migrator.
- Without the new pdo tables, `PdoEffectJournal` and the pdo operator view fail with a `\RuntimeException` naming the missing table (`SchemaCheck` lists it). Without sf schema 011, `DbalEffectJournal` fails on `recorded_at`; `DbalWakeupScheduler` keeps working for intents without a fact.
- `PersistenceConflict` is a `ConflictException` (`BusinessConstraintException`, `\Exception`), no longer a `\RuntimeException`. ddd-symfony has no `catch (\RuntimeException)` around a save; TXP should check its own.
- `DbalWakeupScheduler`, `PdoJobStore` and `WpdbWakeupScheduler` are no longer `final`.
- wp v9 is additive (one nullable column), so a rollback to a 0.6 winner keeps working (B18: it tolerates `installed > DDD_SCHEMA_VERSION`).
