# Wave 4 (round 3): wp-conf4 change requests

Author: wp-conf4 (wave 4, round 3). Branch `wave4/wp-conf4`, based on `1f09b80`. Owned paths: `tests/Integration/**`, `tests/Unit/**` except `tests/Unit/Loader/**`, `packages/ddd-wp/**` (minimal edits in the lms-compat-fix files), the `conformance-wp` and `compat` blocks of `tests/harness/run.sh`, `tests/Compat/rollback/**`, and this file. Binding inputs: [contract-register.md](contract-register.md) (4, 5.1, 5.3, 7.3, 8 wave 4), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling, WP8-10), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (CR-W4CE-5, -9), [wave4-core-process-change-requests.md](wave4-core-process-change-requests.md) (CR-W4P-4, W4P-R5, W4P-R7), [wave4-conformance-4-change-requests.md](wave4-conformance-4-change-requests.md) (W4C4-R2, W4C4-R5), [wave4-packaging-4-change-requests.md](wave4-packaging-4-change-requests.md) (PK4-1).

No ratified interface, frozen FQCN, persisted name, schema column or procedural signature changed. Every item below is additive: a new wp class, a new method on a wp-only class, an interface a wp class now implements, or new wp-only CLI flags. The behaviour changes an existing wp caller can observe are listed under "Behaviour changes".

## What this round did

| Task | Where | Tests |
|---|---|---|
| CR-PDO-6 on the wp claim | `WpdbOutboxStore implements IReportsClaimDeadLetters` | `V8/WpOutboxV8Test` (6 new); `relay.lease-fencing` on wp green |
| wave-4 wp ids | `Wp{Alarm,Codec,Decode}Conformance`, `WpHostFixture implements ProcessDecodeFaults`, `WpCatalogueConformance` wave-4 pin, `check-due.php` / `run.sh conformance-wp` default wave 4 | conformance-wp: 39 of 39 ids due by wave 4 |
| D6 on the wp transport (codec.large-payload) | `WpLargeEnvelope`, `ActionSchedulerTransport`, `WpLedgeredDelivery`, `WpRollbackDrain` | `V8/WpLargeEnvelopeV8Test` (5) |
| D6 in process state | `ProcessRepository` (LargeString wire form, revived by type; corrupt = quarantine reason) | `V8/WpProcessV8Test` (2 new) |
| decode.unknown-class: the worker continues | `WpWakeBracket` | `V8/WpWakeupsV8Test` (updated), `WpDecodeConformance` |
| WP8-10 stranded repairs | `WpStrandedRepairs`, `WpOperatorView::STRANDED_REPAIRS`, `wp ddd ops --resume-stranded/--fail-stranded`, `WpNamedLock::isFreeOrHeldHere()` | `V8/WpStrandedRepairV8Test` (5), `V8/WpProcessV8Test` (updated) |
| 7.3 rollback fixtures | `tests/Compat/rollback/**`, `run.sh compat` 7.3 block | 21 cases (7 x L-0.6.6, L-0.6.5, L-0.6.2) |

## CR-WPC4-1: CR-PDO-6 in `WpdbOutboxStore::claim()`

- **What.** `WpdbOutboxStore implements IReportsClaimDeadLetters`. A selected row that still carries a `claim_token` is a re-claim of an expired N lease (accept, retryLater and deadLetter all clear the token, and the SELECT only takes lease-free rows). It counts one attempt: `attempts + 1`, `last_error` = `LEASE_EXPIRED_ERROR`, an `error_history` entry. `Claim::$attempts` includes it. The re-claim that reaches `max_attempts` is dead-lettered inside the claim's transaction (status `dlq`, lease columns and token cleared, DLQ row with `final_error` = `"<LEASE_EXPIRED_ERROR> N times; dead-lettered at claim"`) and returned by `takeDeadLetteredAtClaim()`, not handed out. It is not replaced within the same claim's limit (as on mem, pdo and sf).
- **Not counted.** A row a 0.6 copy leased (`locked_until` without `claim_token`): 0.6 counts its own attempts. An operator retry (`WpdbOutboxAdministration::retry()`) clears the token, so the next claim is not a re-claim.
- **Why.** wave3-notes CR-PDO-6 ruling; W4C4-R2.

## CR-WPC4-2: by-reference envelopes for facts over Action Scheduler's args limit (D6)

- **What.** New `TangibleDDD\WordPress\Adapter\WpLargeEnvelope` (`MARKER = '__ddd_outbox_ref'`, `ARGS_LIMIT = 8000`, `forTransport()`, `isReference()`, `resolve()`).
  - `ActionSchedulerTransport::submit()` schedules a wrapped envelope whose JSON args exceed 8000 bytes as its journey keys (`__correlation_id`, `__sequence`, `__event_id`) plus `MARKER => ['prefix', 'bytes']`. That is a pointer to the payload in the consumer's `{prefix}_integration_outbox` row, which the relay keeps (`completed`).
  - `WpLedgeredDelivery` resolves it for every DDD-registered callback, inside the ledger gate. A payload that cannot be loaded therefore fails that subscriber's attempt (retried, budgeted, visible) instead of passing silently. The compensation path resolves it too. Redelivery args keep the reference form.
  - Small envelopes keep the 0.6 action shape byte for byte.
- **Why.** Action Scheduler refuses args over 8000 bytes (`ActionScheduler_DBStore::$max_args_length`; `extended_args` is `VARCHAR(8000)`). So a 1 MB `LargeString` fact could not be relayed on wp at all, and `codec.large-payload` was red. 0.6 had the same limit, so nothing that worked before changes.
- **Rollback (7.3).** A 0.6 winner cannot resolve a reference. A by-reference action is an N-only artifact, like `{prefix}_ddd_redeliver`. `wp ddd drain --before-rollback` (`WpRollbackDrain`) now runs due by-reference integration actions. Future ones are not run early, because the delay belongs to the fact. They are counted in `remaining`, so the runbook stops. Covered by `WpLargeEnvelopeV8Test` and `NRowsRolledBackRollback::test_drain_before_rollback_empties_the_pending_redeliveries_first`.
- **Residual.** Raw `add_action` callbacks receive the reference form (outside the guarantee, as documented). A by-reference fact delayed beyond the outbox purge retention can lose its payload. That shows as a failed attempt.

## CR-WPC4-3: LargeString in wp process state, quarantine reason (CR-W4CE-5 request)

- **What.** `ProcessRepository::extract_business_data()` stores a `LargeString` constructor parameter as `LargeString::toPayload()`. `create_instance()` revives a parameter typed `LargeString` (nullable or not) with `fromPayload()`. An `UndecodableLargeString` becomes `\UnexpectedValueException("<Class>::$<param> is an undecodable LargeString: <quarantineReason>")`, which `WpdbProcessStore::find()` writes to `quarantine_reason` (status `failed`, R5).
- **Compatibility.** Only rows of processes with a `LargeString` parameter change. Before this, wp stored such a value through `wp_json_encode` of the object, which mangled binary bytes, so no working row is affected. The 0.6 repository form shares the code.

## CR-WPC4-4: a quarantined wake no longer fails its Action Scheduler action

- **What.** `WpWakeBracket::run()` / `resumeRetry()`: on a `QuarantinedProcess` the intent is still closed as `cancelled` with the reason (WP8-8). The quarantine is logged at error and the exception is **not** rethrown, so the action completes.
- **Why.** `decode.unknown-class`: "status `failed` with `quarantine_reason` set; worker continues". The scenario requires that the drain pass reports no errors. A failed AS action was the only error left, and nothing could ever retry it. The row is the record. Test `WpWakeupsV8Test::test_a_wake_of_a_quarantined_process_closes_its_intent` is updated to match.

## CR-WPC4-5: WP8-10 stranded repairs on wp

- **What.**
  - `WpOperatorView` labels a stranded `running` row `resume-stranded`, `fail-stranded` (`STRANDED_REPAIRS`). A stranded `scheduled` row stays unlabelled: the relay tick continues it.
  - New `WpStrandedRepairs(IDDDConfig, ?IClock)` with `resume($id, ?$expectedVersion)`, `fail($id, $reason, $compensate, ?$expectedVersion)` and `dispatch(ICommand)`. It dispatches core's `ResumeStrandedProcess` / `FailStrandedProcess` to their core handlers on the v8 ports (`WpdbProcessStore`, `WpdbWakeupScheduler`, `ReentrantProcessLock(GetLockProcessLock)`, the clock, `WpdbTransactionBoundary`).
  - `wp ddd ops --consumer=<p> --resume-stranded=<id>` / `--fail-stranded=<id> [--reason=<reason>] [--compensate]`. A refusal (lock held, not stranded, version moved) is core's `ProcessNotStranded`, reported as a CLI error.
- **Fix needed for it (wp-internal).** The core guard takes the process lock with a zero wait and then re-reads `findStranded()` under it. `WpdbProcessStore::findStranded()` reported a `running` row only when `IS_FREE_LOCK` was true on both names, so the guard's own hold hid the row and every repair was refused. New `WpNamedLock::isFreeOrHeldHere(...$names)` (`COALESCE(IS_USED_LOCK(n) = CONNECTION_ID(), 1)`) is used by the scan. A holder in **another** session (a live wake, N or 0.6) still hides the row. `WpNamedLock::isFree()` is unchanged. `WpProcessV8Test::test_a_running_row_whose_lock_is_held_is_not_stranded` now holds the lock from a second session, which is what it models, and also pins the own-session case.
- **Why.** WP8-10 ruling, CR-W4P-4, W4P-R5.

## CR-WPC4-6: 7.3 suite location and the legacy winners (PK4-1, convention change)

- **What.** The suite is at `tests/Compat/rollback/phpunit.xml`, not at PK4-1's `tests/Integration/Rollback/phpunit.xml`. The task places the fixtures under `tests/Compat/rollback/**`. `run.sh` `compat_rollback()` (its 7.3 block, owned by this author) points there. It also builds the legacy winners PK4-1 asked about:
  - every ref of `DDD_ROLLBACK_REFS` (default `v0.6.6 v0.6.5 v0.6.2`) is exported from the clone (`h_export`), `composer install --no-dev --no-scripts`ed on the host, and mounted read-only at `/legacy`;
  - the suite receives `DDD_ROLLBACK_LEGACY="<version>=<dir> ..."`.
  - Each legacy run is a php child, `tests/Compat/rollback/bin/legacy.php`. It loads WordPress, then only that copy (its loader's late branch makes it the winner) and its Action Scheduler, and drives the consumer through 0.6 classes and hooks.
  - The extra `docker run` arguments go through `H_EXTRA_MOUNTS`, as conformance-wp does for its output mount.
- **Packaging (information).** `extraction.yml` can now add a compat job (wave4-packaging-4 "coordinator (CI)"). It needs Docker and Composer's cache or network for the three legacy installs.

## 7.3 coverage (register 7.3, item by item)

| Register 7.3 item | Case |
|---|---|
| Legacy-written outbox rows: pending, dlq + DLQ row, leased, delayed (`delay_seconds > 0`), is_unique; the pause option | `LegacyRowsDrainedByNRollback` |
| Pending AS actions: integration with wrapped envelope, `process_continue ['process_id']`, `await_timeout ['process_id','step_index']`, recurring `outbox_process` | `LegacyRowsDrainedByNRollback` |
| Processes: running, scheduled, suspended on AwaitEvent and AwaitAll, compensating, pre-existing duplicate ignitions (serialized by 0.6.6 / 0.6.5 / 0.6.2) | `LegacyRowsDrainedByNRollback` (decode under N, backfill keeps the duplicate unkeyed, running row stranded with both repairs) |
| Behaviour workflows with work items and meta rows; command audit rows | `LegacyRowsDrainedByNRollback`: written by the legacy copy on its own tables (the meta side table only where the copy has it, 0.6.3+), unchanged over the legacy columns by the N upgrade and drain |
| Rollback: N writes, winner switches to L-0.6.6 and L-0.6.2 (and L-0.6.5), queued work drains and decodes; installed > `DDD_SCHEMA_VERSION` tolerated; N's rows `completed`; quarantined `failed` + reason | `NRowsRolledBackRollback::test_what_n_wrote_drains_and_decodes_under_a_rolled_back_legacy_winner` |
| N-only: future intents fire under 0.6; claimed rows (`claim_token` + lock columns); `ignition_key` / `quarantine_reason` rows; failed ledger rows; pending redeliver lost unless drained (both paths) | `NRowsRolledBackRollback` (3 tests) |
| Sequence (1) upgrade → stranded scan → rollback → drain | `SequencesRollback::test_sequence_1_...` |
| Sequence (2) upgrade → rollback → 0.6 ignition → roll-forward → redeliver | `SequencesRollback::test_sequence_2_...` |
| Sequence (3) v8 migration backfills intents for 0.6-queued wakes | `SequencesRollback::test_sequence_3_...` and `LegacyRowsDrainedByNRollback` |

**Finding (0.6 behaviour, recorded, not changed).** Under every 0.6.x copy, an `#[Async]` step re-schedules its continuation before it runs, on every continuation. So the step never runs and one `process_continue` is always re-queued. `continue_scheduled()` goes through `execute_forward()`, which hits the `#[Async]` check again. N fixed this (`skip_async_once`). Consequences for a rollback: a row N scheduled at an `#[Async]` step stalls under a 0.6 winner (no step re-runs, never more than one continuation queued; pinned by `NRowsRolledBackRollback`). After the roll-forward N completes it. A 0.6-written row of this kind completes under N after the upgrade (pinned by `LegacyRowsDrainedByNRollback`). Add this to the rollback runbook: "processes at an `#[Async]` step do not progress under 0.6 (a 0.6 defect); they resume after the roll-forward."

## Behaviour changes (wp)

1. Expired-lease re-claims count attempts and can dead-letter at claim (CR-WPC4-1). Before, a row whose submitter kept dying was re-claimed forever.
2. Facts over 8000 bytes of AS args are relayed by reference instead of being refused by Action Scheduler (CR-WPC4-2).
3. A quarantined wake completes its Action Scheduler action. Before, it failed the action (CR-WPC4-4).
4. `findStranded()` ignores this session's own hold of the process lock (CR-WPC4-5).
5. A `LargeString` process parameter persists (CR-WPC4-3).

## Requests to other owners

- **WPC4-R1 (coordinator, register).**
  - Record CR-WPC4-6: the 7.3 suite location `tests/Compat/rollback/phpunit.xml`, `DDD_ROLLBACK_REFS`, `DDD_ROLLBACK_LEGACY`.
  - Add the `#[Async]` rollback note above to the runbook.
  - Add to register 3.6 / 5.1: by-reference integration actions are N-only, and are run or counted by `wp ddd drain --before-rollback`.
- **WPC4-R2 (packaging).** `run.sh` `usage()` still says "wp ids due by wave 3 gated" for `conformance-wp`. The gate now defaults to wave 4. That line is outside the blocks this author owns.
- **WPC4-R3 (conformance, optional).** `WpConformanceRuntime::onTheWire()` is now a no-op for scenario envelopes (`wrap()` sends uuid5 correlation ids, W4C4-R2). It is kept for hand-built envelopes and costs nothing. It can be dropped in a cleanup round.

## Not done here (out of scope or other owners)

- D1 / D10 on WordPress (`effect.journal-reuse`, `workflow.fact-ignition-once`) and the D3 ids stay `-` on wp (O9, CR-W4C4-1). There is no EffectMiddleware or ignition-ledger wiring in ddd-wp.
- Packaging's carried requests to wp (wave4-packaging-4) are not in this task:
  - B8: `TANGIBLE_DDD_VERSION` in `Config::version()` / `AdminPage`.
  - The 13 procedural shims under `ddd-wordpress/`, which are not in this round's owned paths.
  - The 7.4 refusals in `hooks.php`: the sibling-core version check and `Defaults/Pdo` under WordPress.
