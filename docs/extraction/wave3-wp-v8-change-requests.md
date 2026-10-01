# Wave 3: wp-v8 change requests

Author: wp-v8 (branch `wave3/wp-v8`, based on `8c74686`). Owned paths: `packages/ddd-wp/{src,wordpress,tests}/**` (except `wordpress/self/index.php`), `tests/Unit/**` except `tests/Unit/{Loader,Process}/**`, `tests/Integration/**` except `tests/Integration/Conformance/**`, `tests/Fakes/**`, `tests/wp-stubs.php`, and this file.

Every request below is additive. No ratified interface, frozen FQCN, persisted name or hook signature was changed.

## WP8-1 (schema, additive): `long_processes.start_path`

- **What.** A fourth nullable column on `{prefix}_long_processes` in schema v8, `start_path VARCHAR(16) NULL`. The v8 `WpdbProcessStore` writes `ignition` from `insertIgnited()` and `manual` from `insert()`. Rows written by a 0.6 copy keep it NULL.
- **Why.** Two binding requirements conflict on wp without it:
  - the ruling on #76 (register 3.8, 5.3 step 6, 7.3 sequence 2): inside the ignition lock, `insertIgnited` also checks `long_processes.ignited_by_event_id` for the class, so a saga ignited by a 0.6 winner between a rollback and a roll-forward is still seen;
  - `process.manual-start-in-drain` (wp, wave 3): a listener's manual `start()` inside the drain stamps `ignited_by_event_id` = the fact id, and "a later `#[StartsOn]` ignition of that class by the same fact still ignites once".
  
  In 0.6 data, a manual start inside a drain and a `#[StartsOn]` ignition write identical rows (same class, same `ignited_by_event_id`, `source = 'event'`, no key). A plain `ignited_by_event_id` check would therefore make N's own manual start block the later ignition. With `start_path`, the check counts only rows a 0.6 copy wrote (`start_path IS NULL`). N's manual rows never block, and N's ignitions are gated by `UNIQUE (process_class, ignition_key)`.
- **Compatibility.** Nullable, no default needed (R5). A 0.6 INSERT names no v8 column, so rows written after a rollback have NULL, which is exactly what the check needs. The v8 migration adds it with `ddd_add_column_if_missing`. No reader of 0.6 depends on it.
- **Register edit.** Section 8 wave 3 wp bullet, the v8 column list: add `long_processes.start_path` (nullable).

## WP8-2 (core, coordination): `IOperatorView` / `OperatorItem` are not on the base yet

- **What.** Register 3.10 puts `Runtime\Ops\{Layer, OperatorItem, IOperatorView}` in core in wave 3. They are not on `8c74686`, and core is building them in parallel. `wp ddd ops` therefore runs on `TangibleDDD\WordPress\Adapter\WpOperatorView::list(?string $layer, int $limit): array`. It returns plain arrays with exactly the `OperatorItem` fields (`layer`, `consumer`, `key`, `attempts`, `budget`, `last_error`, `first_seen`, `repair_actions`) and the `Layer` string values (`relay`, `delivery`, `wakeup`, `process`).
- **Request.** After core's port lands, `WpOperatorView` implements `IOperatorView` and returns `OperatorItem`s. Its `$layer` parameter becomes `?Layer`. This is a wp-internal change; the CLI output does not change. Whoever merges second makes the edit.

## WP8-3 (core, coordination): the ResumeRetry wake entry

- **What.** The register has the runner schedule `WakeKind::ResumeRetry` on lock contention, but no runner entry point for running such an intent exists on the base. `WpdbWakeupScheduler` stores ResumeRetry intents and projects them onto a new hook, `{prefix}_ddd_wakeup` with `['key' => idempotency key]`. That hook has no 0.6 callback and is drained by `wp ddd drain --before-rollback`. The wp callback (`WpWakeBracket::resumeRetry`) calls `ProcessRunner::wake(WakeupIntent)` when the runner has that method. Otherwise it continues a `scheduled` process and closes anything else with a logged warning.
- **Request.** Core names the runner's entry for a claimed or fired intent (`wake(WakeupIntent): void` is what wp probes for), or tells wp which existing method a ResumeRetry maps to. `WakeKind::Deliver` is not used on wp: handler retries go through `{prefix}_ddd_redeliver` (register 3.6), and `WpdbWakeupScheduler::schedule()` throws `LogicException` for it.

## WP8-4 (core, coordination): lock-name order in one statement

- **What.** `GetLockProcessLock` takes the namespaced name and then the legacy `ddd_process_<id>`, in that order, in ONE all-or-nothing statement (`WpNamedLock::acquireBoth`). A failure on the second name releases the first inside the same statement. The legacy name is bound as the statement's first argument. The core-owned wave-2 tests in `tests/Unit/Process/{AwaitTimeoutTest, ProcessLockAcquisitionTest, ProcessIgnitionRaceTest}` identify a lock by the first bound argument of a `GET_LOCK` query and count one GET and one RELEASE per acquisition, and they stay green unchanged.
- **Request.** None to merge. When core migrates those tests to the mem doubles (it owns `tests/Unit/Process/**` this wave), the wp lock is covered by `tests/Unit/WordPress/Adapter/WpProcessAdaptersTest` (statement shape) and `tests/Integration/V8/WpProcessV8Test` (both names held and released on a real MySQL 8; a 0.6 holder of either name excludes; no name is left held on failure).

## WP8-5 (conformance, wave 3): replace the wp fixture stand-ins

The shipped wp code that WPC-1..WPC-3 asked for exists now. The conformance owner can move `WpHostFixture` onto it:

- `transport()`: `TangibleDDD\WordPress\Adapter\ActionSchedulerTransport($config->as_group('outbox'))`. It is the fixture's class with the same semantics; the fault seams (`rejectNext`, `noReferenceNext`) stay in a fixture decorator.
- `outbox()`: `new WpdbOutboxStore($repo, $config, $clock)`. It honours `claim($now, $leaseSeconds)`, fences by `claim_token` and reads the given clock, so `Support/clock-functions.php` is no longer needed by the store or by `WpdbOutboxAdministration($prefix, $clock)`. Note that `OutboxRepository` (the 0.6 form) still reads the wall clock, so the shims can go once nothing in a scenario calls the 0.6 repository.
- `relayPauses()`: `new WpRelayPauseStore($config, $clock)`. `install_outbox_tables()` now also creates `{prefix}_ddd_relay_pauses`. This unblocks `relay.pause-holders`.
- `relay.lease-fencing`: unblocked (claim tokens, requested lease).
- `ledger()` and `subscriptions()`: install the v8 tables for the scenario prefix (`install_tables()`), then set `{prefix}_ddd_schema_version` to 8. `WpHookSubscriptionRegistry` then gates every subscriber through `WpDeliveryLedger` (`HostDefaults::for(IDeliveryLedger::class, $config)`), and `LedgerGatedSubscriptions` can be dropped. Without the option the wp gate stays off, so the current stand-in keeps working until then.
- The scenario tables to drop in `tearDown()` grow by `ddd_relay_pauses`, plus `ddd_wakeups` and `ddd_delivery_ledger` once `install_tables()` is used.

## WP8-6 (packaging, optional): phpstan symbol discovery for Action Scheduler classes

- **What.** `phpstan.neon` scans only `vendor/woocommerce/action-scheduler/functions.php`. `WpRollbackDrain` needs `ActionScheduler::runner()->process_action()` (there is no procedural form), so it resolves the runner dynamically (`call_user_func(['ActionScheduler', 'runner'])`) behind `class_exists`. The v8 migration uses `as_get_scheduled_actions(…, OBJECT)` and the literal status `'pending'`.
- **Request.** Add `vendor/woocommerce/action-scheduler/classes/ActionScheduler.php` and `classes/abstracts/ActionScheduler_Store.php` (or the classes directory) to `scanFiles`/`scanDirectories`, so these calls can be written statically.

## WP8-7 (schema, additive; fix round 1): `ddd_delivery_ledger.redelivery`

- **What.** A nullable `redelivery LONGTEXT NULL` column on the new v8 table `{prefix}_ddd_delivery_ledger`. When a DDD subscriber fails, `WpDeliveryLedger::markFailedFor()` (a wp method next to the port's `markFailed()`, which is unchanged) also stores the fact's `{prefix}_ddd_redeliver` args (`['hook', 'event_class', 'payload']`).
- **Why.** Review finding (minor, delivery): when Action Scheduler fails or never stores a redelivery, nothing could schedule it again, because the ledger did not know the payload. Now each relay tick runs `WpLedgeredDelivery::restoreRedeliveries()`. It schedules a new redelivery for every fact with a `failed` subscriber and no pending or running redeliver action, at `max(now, last failure + backoff(attempts))`. `wp ddd drain --before-rollback` restores lost redeliveries before each round, and counts any it still cannot restore as `remaining`.
- **Compatibility.** The table is new in v8 and no 0.6 copy reads it. `ddd_migrate_v8()` adds the column with `ddd_add_column_if_missing`, so a ledger created by an earlier v8 build is healed.
- **Also in this fix.** Redeliveries now use the consumer's `IDDDConfig::hook('ddd_redeliver')` and `as_group('outbox')`. The config is the one `register_delivery_hooks()` saw, else `ConsumerRegistry::config_for()`, else the 0.6 naming. They run on the host `IClock`. A `0` action id is logged, and the next tick restores that redelivery.

## WP8-8 (wake budget; fix round 1): `exhausted` intents, `rearm`, `WpStrandedReport::$exhausted`

- **What.** Register 5.1's wake-layer budget is enforced in `WpdbWakeupScheduler`:
  - A failed wake (`finish()`, `finishKey()`, a stale `firing` row in `reproject()`) spends one attempt. It goes back to `pending` with `due_at = now + min(300, 2 × 2^n)` s, so a retry is re-projected only once its backoff is due.
  - The 10th failure sets status `exhausted`, a new value in the new table's `VARCHAR` status column. The row then leaves `reproject()`. It is listed in the operator view (layer `wakeup`, budget 10, repair `rearm`) and logged once.
  - Scheduling an exhausted key again is a no-op. Re-arming a `firing` key keeps its attempts. A wake that returns resets the count of the keys it re-armed.
  - `QuarantinedProcess` is terminal: the intent is `cancelled` with the reason.
  - New wp-only surface: `WpdbWakeupScheduler::{WAKE_BUDGET, BACKOFF_*, backoffSeconds(), rearm(), hasExhaustedIntent()}`, an optional `$terminal` parameter on `finish()` / `finishKey()`, `wp ddd ops --consumer=<p> --rearm=<key>`, an optional `WpStrandedReport::$exhausted` (the stranded scan does not mint a new continuation for a process whose wake is exhausted), and an optional `WpRelayTickReport::$redeliveriesRestored`.
- **Why.** Review finding (major): without the budget, a deterministic failure such as an undecodable row looped once per tick forever. `WpdbProcessStore::find()` also no longer rewrites an already-quarantined row, so a repeated find does not bump the version.
- **Register edit (requested).** Register 3.6, wp implementation: name the intent statuses `pending | firing | done | cancelled | exhausted`. Register 5.1, wake row: "exhaustion goes to the `exhausted` intent status (operator view, layer `wakeup`, repair `rearm`)".
- **Operator view repairs.** These were cut down to what is implemented. `rearm` applies to exhausted wakeups. Delivery rows list no repair (retries are automatic). The unimplemented `redeliver` and `reproject` labels are gone.

## WP8-9 (behaviour, documented limitation; fix round 1): subscriber ids of `integration_action()` closures

- The ledger budget is counted per (event_id, subscriber_id) (register 3.5, 5.1), which assumes subscriber ids are stable.
  - Ids that are stable across deploys: `listener:<Class>`, `action:<Class>::<method>`, function names, and the ProcessRunner ignition and resume ids.
  - Ids that are not: a closure passed to `integration_action()` is named `action:Closure@<path>:<line>` (or `<Class>@<path>:<line>` when it is bound), plus `#n` by registration order on the hook. An edit that moves the line, or a change in registration order, changes the id.
- **Consequence.** If a deploy lands between a failure and its redelivery, an already-delivered closure subscriber of that fact loses its ledger row, so it runs again. Two closures on the same line can also swap `#n`.
- **Guidance (changelog).** For listeners whose double run matters, use a class (`integration_listener()`) or a `[Class, 'method']` callable. Giving `integration_action()` an explicit subscriber-id parameter would change a frozen procedural signature (B14 snapshot). It is left to the coordinator: if ratified, add an optional trailing `?string $subscriber_id = null` and re-freeze the snapshot.

## Fix round 1: other changes reviewers should know

- **Migration safety.** `ddd_add_unique_index_if_missing()` verifies the index after its `ALTER` and throws when it is missing. `ddd_maybe_migrate()` catches a failing explicit migration, logs it, stores it in `{prefix}_ddd_migration_error` and does **not** bump the schema version, so the v8 adapters stay off and the next trigger retries. Before this, an exception escaped from `init`. The ignition backfill counts only a 1062 as a duplicate and rethrows any other error. It now also requires `source = 'event'`, as X7 states, so there is no deviation to record.
- **`IOutboxStore` gating.** `WpHostPortFactory` serves `WpdbOutboxStore` only to a consumer at schema v8, because its SQL names `claim_token`. Otherwise it returns null and the caller keeps the 0.6 repository path.
- **Lock timeout.** In `WpNamedLock::acquireBoth()` the second `GET_LOCK` waits only for the remaining time (`NOW(6)` is the statement start, `SYSDATE(6)` is the current time). The whole call waits at most `timeout + 1 s`. The bound arguments and the statement shape that the core tests read are unchanged.
- **Unchecked writes.** These now throw `\RuntimeException` (or `ProcessStoreFailed`) on a failed query: every `WpdbWakeupScheduler` write (`cancel`, the `claimDue` lease, which rolls back the claim, `begin`, `finish`, `finishKey`, `beginKey`, `reproject`, `rearm`) and the `WpdbProcessStore::find()` quarantine write. `WpWakeBracket` logs a failed bookkeeping write. After a failed wake it keeps the wake's own exception.
- **R5 test.** The unit test that checked hand-written column definitions is replaced by an integration test. It reads `information_schema` after a v7 → v8 upgrade and asserts that every column added to a table 0.6 writes is nullable or defaulted.
- **Changelog entry (still needed).** `is_unique` cancellation now matches the payload signature and never cancels leased rows (O6, C26). 0.6 cancelled every pending row of the type.

## Compatibility summary (for reviewers)

- Schema v8 is additive (R5). A 0.6 winner after a rollback inserts and updates rows unchanged, and tolerates `installed > DDD_SCHEMA_VERSION`. Outbox rows stay `completed`. Claimed rows carry `locked_until`/`locked_by`, so a 0.6 fetch skips them. Intents are projected on the legacy hooks with the legacy associative args, future-dated.
- Every v8 path is gated on the consumer's `{prefix}_ddd_schema_version >= 8` (`WpSchema`). Until its migration runs, and for consumer-authored repositories or publishers, the wave-2 paths stay in use: `WpRepositoryProcessStore`, `ActionSchedulerWakeupScheduler`, the 0.6-form relay, and unledgered callbacks with 0.6 propagation.
- On a migrated consumer, DDD-registered callbacks are isolated: a throwing listener no longer aborts the rest of `do_action` or fails the Action Scheduler action. It is retried through `{prefix}_ddd_redeliver` (lost on rollback unless `wp ddd drain --before-rollback` ran first). Raw `add_action` callbacks are outside the guarantee, as documented.
- `is_unique` cancellation now matches the payload signature and never cancels leased rows (O6, C26). 0.6 cancelled every pending row of the type. This needs a changelog entry.
- The DLQ `attempts` count includes the dead-lettering attempt (WPC-5, already in wave 2).
