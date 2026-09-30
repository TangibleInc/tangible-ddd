# Coordinator rulings on the wave-0 contract register

Date: 2026-10-01. Applies to [contract-register.md](contract-register.md). Reviews: code-truth (needs-changes, 19 findings) and host-feasibility (needs-changes, 2 blockers + 11 major/minor). These rulings are binding; the register is revised to match and this file stays as the record.

## Operator constraints (unchanged, restated)

- No bundled MySQL runtime, daemon or migrator. Raw PHP gets `IHostConnection` + `PdoConnection(\PDO)`, plain schema SQL, `Drain::runOnce()`.
- MySQL 8 and Postgres are claimed. MariaDB is **not** claimed and gets **no** smoke leg (O2 rejected: operator chose MySQL only).
- The three bugs are fixed on the extraction branch; a 0.6.x hotfix branch is prepared, never released by us.
- Branch pushed, no PR, nothing tagged or published.

## Register self-made decisions X1-X12 and the rest

Accepted as written, with these corrections:

- **X1** restated per code-truth #58: 0.2.x is unsupported *by decision*; the natural failure mode is a silent mixed load. `load.v0-2-negative` detects it actively by probing for 0.2-only FQCNs (e.g. `CorrelationContext`) at winner boot and raising a named error in debug, logging otherwise.
- **X4** citation fixed per #60.
- **X7** replaced (code-truth #50): ignition dedup uses a new nullable column `ignition_key` set **only** by the `#[StartsOn]` ignition path (`uuid5(event_id, process_class)`); unique on `(process_class, ignition_key)`. Manual starts inside a drain keep `ignited_by_event_id` as today and are never deduped. New scenario `process.manual-start-in-drain`. On wp, `insertIgnited` also checks `long_processes.ignited_by_event_id` for the same class inside the ignition lock (host-feasibility #76), so upgrade → rollback → roll-forward cannot double-ignite. The wp backfill reports duplicates only for rows that came from the ignition path.
- **X8** amended (#59): `Subscriber` carries the numeric priority; the three named phases are constants (10/50/99), not an enum. Only DDD-registered callbacks are isolated and ledgered on wp; raw `add_action` callbacks are documented as outside the guarantee.
- **X3 / 5.2** amended (host-feasibility #72, #83): on sf, `ProcessRunner::start()` persists the process and writes a `Continue` intent in the caller's transaction; the first step runs in a worker. In-band first step is an opt-in (`ddd.process.inband_start: true`) that requires the direct connection. Scenario `process.start-from-web`. Step effects are protected by idempotency (deterministic command ids + D1 journal), not by the lock; before each step dispatch the runner does a fenced version touch.
- Retry budgets, deterministic ids, DB matrix accepted. Matrix: MySQL 8.0 gating (8.4 optional, non-gating), Postgres 16 gating (17 optional). No MariaDB.

## Blockers

1. **Wave 2 cannot move ProcessRunner without the process ports** (host-feasibility #70). Ruling: option (b). Every port interface in 3.2-3.8 (`ITransactionBoundary`, `IOutboxStore`, `IOutboxAdministration`, `IProcessStore`, `IProcessLock`, `IWakeupScheduler`, `ISubscriptionRegistry`, `IAuditSink`, `IActorProvider`, `IFactObserver`, `IClock`, `IHostConnection`) plus its in-memory double lands in **wave 1**. Wave 2 moves code onto the ports and ships a transitional WP-backed implementation of each, so ProcessRunner's core form compiles and runs against mem doubles at the end of wave 2.
2. **Scenarios scheduled before their machinery** (#71). Ruling: split each affected scenario into a process-free variant (subscriber ledger, phase order with stub ignition/resume subscribers) and a process variant. The register lists exact scenario ids per wave per host, no globs. `lock.acquire-error` moves to wave 3.

## Majors

- **Status vocabulary** (code-truth #51, host-feasibility #77): wp keeps writing `completed`; `accepted` is a read alias in the port. Quarantine is a new nullable `quarantine_reason` column with status `failed`. The wp claim sets `locked_until`/`locked_by` as well as `claim_token`, so a 0.6 copy running during a deploy stays excluded. R5 ("new tables or nullable/defaulted columns only") holds without exception.
- **Rollback with pending durable rows** (code-truth #52, host-feasibility #74, #75): wp projects every wakeup intent to Action Scheduler at schedule time, future-dated, on the legacy hook with the legacy **associative** args (`process_id`, `step_index` keys frozen in R4, per #65). The intent row is the recovery ledger and fencing source. `{prefix}_ddd_redeliver` actions are documented as lost on rollback, and a `wp ddd drain --before-rollback` command empties them first. The stranded scan checks `as_has_scheduled_action(hook, args)` before minting an intent, and the v8 migration backfills intent rows from pending AS actions. 7.3 fixtures add N-only artifacts: future intents, pending redeliver actions, failed ledger rows, and the upgrade → stranded scan → rollback → drain sequence asserting no step re-runs.
- **lock.namespace on wp** (#53, #73): marked `-` for wp while the legacy `ddd_process_<id>` name is also taken; cross-consumer serialization is a known cost, not a correctness issue. Removed from the hotfix scenario set.
- **Hotfix lock failure** (#54): the hotfix keeps its scope small. On NULL/contended lock it throws `LockingException`; Action Scheduler records the failed action, visible and manually retryable in the AS admin. Section 6 states this weaker guarantee; `lock.contention` "later succeeds" is extraction-branch only.
- **Hotfix replay identity** (#55): out of hotfix scope. Section 6 states that replay still double-ignites on 0.6.7. `relay.replay-keeps-identity` is extraction-branch only.
- **ConsumerHandle** (#56): stores `IConsumerIdentity`, adds `identity()`, `config()` returns `IDDDConfig` or throws `NotAWordPressConsumer`. Call sites (`config()`, `matches_registration()`, `config_for()`, WP dashboard) listed in 1.4 as a split.
- **IntegrationConformance** (#57): added to the split list; core targets `IntegrationTranslator`, wp subclass keeps the constructor-side-effect check.
- **D10 on Postgres** (#78): sf wave 3 adds `DbalBehaviourWorkflowRepository`, `DbalWorkItemRepository` and a workflow ignition ledger with a unique dedup key; scenario `workflow.fact-ignition-once` (same CronEntryDue twice, two ticks in one minute → one run).
- **D1 semantics** (#79): the journal is keyed by `idempotencyKey()`. Repair is explicit: `IEffectJournal::invalidate(key, reason)` runs in the repair command's transaction before it re-dispatches, so TXP's `RepairStripeCustomer` performs again. Budget exhaustion is counted in the per-subscriber delivery ledger; the core invoker fires `failureCommand()` when that subscriber hits its budget, never from `WorkerMessageFailedEvent`. Inside a process step, D1 perform retries follow the step's retry policy (default 0 → compensate); the journal makes a process-level retry reuse the result.
- **Raw-PHP composition root** (#80): core ships `SubscriptionRegistrar` (`registerListener(class|object)`, `registerProcess(class-string<LongProcess>)`, reads `#[StartsOn]`/`#[Awaits]` by reflection), used by wp, sf and pdo alike. `DurableRuntime::compose(IHostConnection, IConsumerIdentity, ContainerInterface|array $handlers, list<class-string> $listeners, list<class-string> $processes, ?IClock)` is frozen. `examples/plain-php-durable` is its acceptance fixture.
- **Ownership disjointness** (#81): `tests/Unit/Loader/**` carved out of wp to packaging; `packages/ddd-conformance` owns its own composer.json; the `ddd-wordpress/self/index.php` handoff to packaging happens at the end of wave 2; core commits wave-3 interfaces as step 0 (now moot, they land in wave 1); core temporarily owns `tests/Unit/Process/**` during the ProcessRunner migration.

## Minors

All accepted as the reviewers proposed: #61 counts, #62 OutboxProcessor couplings, #63 citations, #64 (replay deletes the DLQ row as today; retry keeps the int-id command shape), #66 (wave 1 applies the schema-free fix variants to both branches, the extraction variants replace them in wave 3; hotfix diff = three fixes + stub + yaml require + tests only, so O1 is rejected for the hotfix branch; list the tags that exist), #67 legacy TransactionMiddleware behaviour, #68 wording, #82 (PdoConnection binds ints with `PARAM_INT`; conformance runs once with `ATTR_EMULATE_PREPARES=true`; `isDuplicateKey` = MySQL 1062 / Postgres 23505 only), #84 (`DDD_CLOCK_OFFSET` env var read by a test-only `IClock`).

## Open decisions O1-O20

Defaults accepted except: **O1** rejected for the hotfix branch (loader entry lands on extraction only). **O2** rejected (no MariaDB). **O12**: Packagist indexes only the root `composer.json` (name `tangible/ddd`), so new `packages/*` names are not registered by pushing; WIP branch versions `dev-extraction/*` are acceptable. Keep pushing.

## Needs a person (not blocking)

Owners of certificates and reporting; which sites combine which consumers; whether tangible-ddd is activated as a plugin in production; whether any build step renames vendor packages; who approves a 0.6.7 release. Carried to the final report.

## Scope fence

WP rollback machinery (7.3) is required but ships in wave 4 after the Symfony path is proven. The TXP slices (`tenancy-reference`, then `process-kernel`) are the acceptance for ddd-symfony; `billing-accounts` is added only if D1 is not otherwise exercised end to end.
