# Wave 4: pdo-4 change requests

Author: pdo-4 (wave 4, pdo-default). Branch `wave4/pdo-4`. Owned paths: `packages/ddd-core/src/Defaults/Pdo/**`, `packages/ddd-core/schema/mysql8/**`, `packages/ddd-core/tests/Pdo/**` except `tests/Pdo/Conformance/**`, `examples/plain-php-durable/**`, and this file. Binding inputs: [contract-register.md](contract-register.md) (3.8, 3.10, 3.11, 4, 5.1, 8 wave 4, O8), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling, WP8-10, L5), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (CR-W4CE-1, -5, -9), [wave4-core-process-change-requests.md](wave4-core-process-change-requests.md) (CR-W4P-1, -4, -5; W4P-R5, W4P-R6), [wave4-sf-a-change-requests.md](wave4-sf-a-change-requests.md) (the sf twins of the stores, CR sf-a-4/5).

No core file was touched and no ratified interface changed. Every item is additive: new pdo classes, new methods on pdo-only classes, new optional parameters, new schema files. The one observable change on an existing pdo class is noted under "Behaviour changes".

## What this round did

| Task | Where | Tests (MySQL 8, both prepare modes) |
|---|---|---|
| D3 route index, keyed `findWaitingFor`, `IMatchesFactAncestry` (W4P-R1/R6) | `PdoProcessStore`, `schema/mysql8/007_process_waits.sql` | `PdoProcessStoreCases` (8 new), `DurableRuntimeWave4Cases::test_a_keyed_fact_resumes_only_the_process_that_minted_its_key` |
| D7 long alarms | `PdoJobStore` (unchanged: one absolute intent) | `PdoJobStoreCases::test_a_long_alarm_...`, `DurableRuntimeWave4Cases::test_a_25_hour_alarm_fires_once_at_its_absolute_instant` (24h59m59s, restart, +48 h) |
| CR-PDO-6 core rule | `PdoOutboxStore::claim()` + `IReportsClaimDeadLetters` | `PdoOutboxStoreCases` (6 new, incl. the core `OutboxProcessor` and the operator view) |
| D10 stores (O8) | `PdoBehaviourWorkflowRepository`, `PdoWorkItemRepository`, `PdoWorkflowIgnitionLedger`, `008_workflows.sql` | `PdoWorkflowStoresCases` (18, incl. core `WorkflowIgniter`: a fact twice + two ticks a minute = one workflow; a failed save releases the key) |
| D1 journal | `PdoEffectJournal`, `009_effect_journal.sql` | `PdoEffectJournalCases` (12) |
| D1 wiring | `DurableRuntime::compose()`: `EffectMiddleware` between Correlation and Transaction | `DurableRuntimeWave4Cases` (journal reuse after a failed `record()`, repair by `invalidate()` in its own transaction, rolled-back repair) |
| Operator repairs (W4P-R5, WP8-10) | `PdoOperatorView::repair()` / `repairItem()`, `Internal\OperatorRepairs`, `PdoRepairRefused`; `DurableRuntime` handles `ResumeStrandedProcess` / `FailStrandedProcess` on its bus | `PdoOperatorViewCases` (12 new), `DurableRuntimeWave4Cases` (3) |
| D6 in process state (CR-W4CE-5 request) | `Internal\ProcessCodec` | `PdoProcessStoreCases` (1 MB binary round trip, nullable, corrupt = quarantined with the reason) |
| L5 for mysql8 | `schema/mysql8/released.txt`, `SchemaSql::released()/digest()`, `statements()/dump()` `$since` | `Native/SchemaReleasedTest` (5) |

## CR-PDO4-1: D3 await routes on pdo (`ddd_process_waits`, 007)

- **What.** `PdoProcessStore` rewrites one `{prefix}ddd_process_waits` row per `LongProcess::await_routes()` route (event class, await key, `''` = unkeyed) with every insert and save, in the caller's transaction or one of its own. A process that is not `suspended` has no rows; quarantine clears them. `findWaitingFor($class, $key)`: routes whose class is `$class` or a parent or interface of it; `null` = any key, `''` = unkeyed routes only, otherwise that key. A suspended row with no routes at all (written before 007 was applied) is still matched through `waiting_for` for a `null` or `''` key. The store declares `IMatchesFactAncestry` (W4P-R6).
- **Effect.** An AwaitAny row is found by its branch classes, not by the common ancestor in `waiting_for` (so a fact of an unrelated class no longer loads it as a candidate). A keyed fact loads only the process that minted the key.
- **Why.** Register 3.8/3.11 D3 (`findWaitingFor(class, key)`), W4P-R1 (the sf route index), W4P-R6.

## CR-PDO4-2: CR-PDO-6 in the pdo claim

- **What.** `PdoOutboxStore implements IReportsClaimDeadLetters`. The claim UPDATE adds `attempts + 1` and `last_error = LEASE_EXPIRED_ERROR` for rows that still carried a lease (MySQL evaluates SET left to right, so both read the old `claim_token`). A re-claimed row whose attempts reach `max_attempts` is dead-lettered inside the claim transaction (DLQ row, status `dlq`, lease cleared, error `"<LEASE_EXPIRED_ERROR> N times; dead-lettered at claim"`) and returned by `takeDeadLetteredAtClaim()`, not handed out. A row released by an outcome (`retryLater`) is not a re-claim. Like mem and sf, a claim-time dead letter is not replaced within the same claim's limit.
- **Why.** wave3-notes CR-PDO-6 ruling, CR-W4CE-9 ("pdo and wp: implement the rule in their claim()").

## CR-PDO4-3: D10 stores on MySQL (O8)

- `PdoBehaviourWorkflowRepository(EventsUnitOfWork, IHostConnection, string $tablePrefix = '', ?IClock $clock = null)`: the wp/sf semantics (meta side table, correlation id from the ambient scope, `get_by_id()` of an unknown id throws `\RuntimeException`), row + meta in one transaction.
- `PdoWorkItemRepository(IHostConnection, string $tablePrefix = '', ?IClock $clock = null)`: a new item is an upsert on `(workflow_id, behaviour_idx, phase, item_key)` (`ON DUPLICATE KEY UPDATE ... id = LAST_INSERT_ID(id)`), so two writers end with one row and both items hydrated with its id.
- `PdoWorkflowIgnitionLedger(IHostConnection, string $tablePrefix = '', ?IClock $clock = null) implements IWorkflowIgnitionLedger`: `claim()` is a plain INSERT; MySQL 1062 (only) = `false`. Not `INSERT IGNORE`, which would turn a too-long key or a missing table into a silent lost claim. `created_at` comes from IClock, which the igniter's stale rules compare against.
- Tables: `008_workflows.sql`, the sf 008 shape (`dedup_key` and `kind` are `VARCHAR(191)`, the utf8mb4 index limit; keys from `WorkflowIgnitionKey` are 36 characters or `scope:minute`).
- `DurableRuntime`: `workflows()`, `workItems()`, `workflowIgnitions()` accessors and container services (`IBehaviourWorkflowRepository`, `IWorkItemRepository`, `IWorkflowIgnitionLedger` plus the pdo classes). compose() does not register `IStartsFromFact` workflows itself (its signature is frozen and takes no workflow list); a host builds `new WorkflowIgniter($rt->workflowIgnitions(), $rt->boundary(), clock: ...)` and calls `register()` before its first drain. See Open 1.

## CR-PDO4-4: D1 `PdoEffectJournal` (009) and the bus wiring

- `PdoEffectJournal(IHostConnection, string $tablePrefix = '', ?IClock $clock = null) implements IEffectJournal`: the DbalEffectJournal contract (store = upsert that clears an invalidation; invalidate = mark, keep reason and counter, no-op for unknown or already invalidated keys; `\RuntimeException` on storage or decode failure). `result_json` is LONGTEXT, not JSON, so key order round-trips. Keys are `VARCHAR(191)`; a longer key is refused at `store()` (sf allows TEXT).
- `DurableRuntime::compose()` now runs Correlation → **Effect** → Transaction → DomainEventsPublish → SelfExecuting → handler (CR-W4CE-1). `effectJournal()` accessor; `IEffectJournal` is a runtime service, so a repair command's `handle(IEffectJournal $journal)` gets it.

## CR-PDO4-5: operator repairs on `PdoOperatorView`

- New public methods `repair(Layer $layer, string $key, string $action, array $options = []): void` and `repairItem(OperatorItem $item, string $action, array $options = []): void`; new `PdoRepairRefused extends \RuntimeException`. Constructor unchanged. Action table in the class docblock and the package README (relay retry/replay/discard through `PdoOutboxAdministration`; `redeliver` and `retry_wake` make an unleased job due now; `resume_stranded` / `fail_stranded` through the core WP8-10 handlers with the zero-wait lock guard).
- `DurableRuntime` registers the two core repair handlers as default handlers (the host's array entry wins; `RuntimeContainer::setDefaultHandler()`, internal), so `$rt->bus()->handle(new ResumeStrandedProcess('prefix', $id))` works, audited by the act bracket.

## CR-PDO4-6: append-only schema gate for mysql8 (L5)

- `schema/mysql8/released.txt` lists every shipped file with `SchemaSql::digest()` (sha256 of its statements, comments and whitespace ignored). `SchemaReleasedTest` fails when a listed file's statements change, a file is unlisted or out of order, numbering has a gap, or a statement is not `CREATE TABLE IF NOT EXISTS` (MySQL 8 has no `ADD COLUMN IF NOT EXISTS`; new state goes in new tables).
- `SchemaSql::statements(string $prefix = '', ?int $since = null)` / `dump(..., ?int $since = null)` (optional trailing parameter): only the files numbered above `$since`; a wave-3 host applies `dump($prefix, 6)`.
- 007-009 are listed now, as sf did with its 009, because process-kernel builds on this round. If review changes one of them before the merge, regenerate its line; after the merge they are frozen.

## Behaviour changes (pdo only)

1. `PdoProcessStore::findWaitingFor()` reads the route index. Results for 0.6-shaped awaits are unchanged; AwaitAny rows are no longer returned for facts of unrelated classes that merely share the ancestor; keyed lookups narrow by key.
2. Expired-lease re-claims count attempts and can dead-letter at claim (CR-PDO-6).
3. `PdoJobsOperatorSource` lists `redeliver` on failed deliver jobs (was no repair).
4. A process with a `LargeString` constructor parameter now persists (it failed with a JSON encoding error before for binary values).
5. `DurableRuntime`'s bus refuses an `IExternalEffectCommand` dispatched inside an open transaction (`EffectInsideTransaction`), as `EffectMiddleware` does on every host.

## Requests to other owners

- **CR-PDO4-R1 (pdo conformance, `tests/Pdo/Conformance/**`):** the four wave-4 pdo cells. `PdoHostFixture` hand-composes compose()'s classes, so it should add `EffectMiddleware(new PdoEffectJournal($db, $prefix, $clock), $boundary)` between Correlation and Transaction for `effect.journal-reuse`; `process.alarm-long` can use `AwaitAlarm::after(25 * 3600)` (the runtime case here shows the shape); `codec.large-payload` and `decode.unknown-class` work through `ProcessCodec` now. `relay.lease-fencing` can add the re-claim assertion on pdo. `process.crash-mid-step` can resume through `ResumeStrandedProcess` on the bus. The fixture's `DELETE` over `SchemaSql::TABLES` picks up the five new tables automatically.
- **CR-PDO4-R2 (packaging):** `schema/mysql8/released.txt` must ship in the release artifact with the `.sql` files (it does under the current `.gitattributes`, which keeps `schema/`). `run.sh core-pdo` needs no change.
- **CR-PDO4-R3 (coordinator, register):** record L5 for `schema/mysql8` next to sf's (released.txt digest gate; CREATE TABLE only on MySQL 8).

## Open

1. compose() has no seam to register `IStartsFromFact` workflows (frozen signature). Additive options, for a ruling: (a) accept `IStartsFromFact` classes in `$listeners` and register them through a `WorkflowIgniter` on the runtime's ledger, or (b) a new `DurableRuntime::registerWorkflow(IStartsFromFact)`; either way the delivery worker's event-class map is built once in compose(), so a fact class first named by a later registration is delivered only if its outbox row carries the class (compose()'s bus always writes it). pdo has no `workflow.fact-ignition-once` cell (`-`), so this is not gating.
2. `redeliver` on a ledger item whose fact's deliver job already completed is refused (`PdoRepairRefused`): the pdo deliver job only completes when no subscriber needs a retry, so this means the subscriber was exhausted or delivered. Re-running a single exhausted subscriber would need a ledger reset repair, which no register row asks for.
3. CR-PC-3's open point (a deliver job that can never be delivered retries forever at the 3600 s cap) is unchanged; the operator can now see and redeliver it, not drop it.
