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

## Compatibility summary (for reviewers)

- Schema v8 is additive (R5). A 0.6 winner after a rollback inserts and updates rows unchanged, and tolerates `installed > DDD_SCHEMA_VERSION`. Outbox rows stay `completed`. Claimed rows carry `locked_until`/`locked_by`, so a 0.6 fetch skips them. Intents are projected on the legacy hooks with the legacy associative args, future-dated.
- Every v8 path is gated on the consumer's `{prefix}_ddd_schema_version >= 8` (`WpSchema`). Until its migration runs, and for consumer-authored repositories or publishers, the wave-2 paths stay in use: `WpRepositoryProcessStore`, `ActionSchedulerWakeupScheduler`, the 0.6-form relay, and unledgered callbacks with 0.6 propagation.
- On a migrated consumer, DDD-registered callbacks are isolated: a throwing listener no longer aborts the rest of `do_action` or fails the Action Scheduler action. It is retried through `{prefix}_ddd_redeliver` (lost on rollback unless `wp ddd drain --before-rollback` ran first). Raw `add_action` callbacks are outside the guarantee, as documented.
- `is_unique` cancellation now matches the payload signature and never cancels leased rows (O6, C26). 0.6 cancelled every pending row of the type. This needs a changelog entry.
- The DLQ `attempts` count includes the dead-lettering attempt (WPC-5, already in wave 2).
