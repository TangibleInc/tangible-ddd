# Wave 0 / E-symfony-host: what ddd-symfony must provide for TXP

- Role: E-symfony-host (read-only investigation)
- Source: `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages`, pinned `598858c` (0.6.6)
- Inputs read: TXP `specs/tangible-ddd-extraction.md` (with the 2026-09-30 review), `specs/module-map.md` (D1-D14, the slice list, the Gate 1 decisions and the risks), `research/2026-09-30-domain-decomposition/00-rollup.md`, `research/2026-09-30-symfony-ddd-options/00-rollup.md`, `research/2026-09-30-tangible-ddd-abstraction-cost/00-rollup.md`
- All `path:line` citations are relative to the tangible-ddd checkout unless prefixed with `txp:`.
- Operator decisions treated as fixed: no bundled MySQL runtime, worker daemon or migration framework in core. Plain-PHP hosts pass their own connection, e.g. a `$db` PDO. Core ships ports, in-memory doubles, at most a thin PDO adapter and a `runOnce()`/drain entry point. MariaDB is not a target. The three verified bugs are fixed on the extraction branch. Tactician is kept. WordPress consumers are preserved. Nothing is published.

## 1. Summary

ddd-symfony is not a thin wiring layer. Before TXP's `tenancy-reference` slice can run, it needs:

1. a DBAL transaction boundary and a Postgres outbox with fenced claims;
2. a relay that hands facts to a Messenger Doctrine transport on the **same** connection, so the hand-off is atomic;
3. Messenger handlers that reproduce the WordPress drain bracket (unwrap, `Correlation::within(for_fact)`, listener, then ignition, then resume, in that order);
4. worker reset for the static and stateful runtime;
5. compile-time replacements for the WordPress boot-time side effects: listeners that self-register in their constructors, and the eager namespace scan.

On top of that, `process-kernel` needs:

1. a Postgres process-lock port;
2. atomic ignition;
3. durable wake/alarm intent rows;
4. a post-commit relay wakeup.

Of the 14 TXP demands, D11 exists today. D13 and D8 exist in part. D3 and D10 exist only as partial primitives. The rest are absent.

Four verified defects in the current code matter specifically under multiple Messenger workers. They go beyond the three bugs the operator already scheduled:

- `resume_on_event` reads processes outside the lock and never re-reads them (lost AwaitAll arrivals).
- `mark_completed` is unfenced.
- The step's commands are dispatched *before* the await is persisted.
- `command_id` is random per dispatch, so a redelivered fact produces a different command id. That breaks the D1 journal keyed "by command_id" as TXP's module map specifies.

**Postgres lock recommendation:** session-scoped `pg_try_advisory_lock(bigint)` polled against a deadline. The key is namespaced by consumer. It runs on the worker's direct (non-pooled) connection and is released in `finally`, with state re-read under the lock. Transaction-scoped locks cannot work while a wake spans several independently committed commands (section 6).

## 2. Existing Symfony DI usage inside tangible-ddd

| # | Claim | Evidence | Proposed owner | Compatibility risk | Test that proves it | Unresolved |
|---|---|---|---|---|---|---|
| S1 | Core's only Symfony DI code is the process-catalog compiler pass. It is pure DI with no WordPress calls, so a Symfony `Bundle::build()` can register it as-is. | `ddd-src/Infra/DependencyInjection/DDDCompilerPasses.php:10-17`, `LongProcessCatalogPass.php:13-36` | symfony (move out of core; keep a WP wrapper) | Low. WP hosts call `DDDCompilerPasses::register()`, so the class name must survive in ddd-wp or as an alias. | Kernel test: a bundle registers the pass, the container is dumped, and `LongProcessCatalog` lists a tagged process (the existing `tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest.php` ported to a kernel) | Does core keep `LongProcessCatalog` (a plain value) while the pass moves? Recommended: yes. |
| S2 | Everything else resolves through PSR-11 `ContainerInterface`, which suits Symfony. The trap is that the resolution needs **public** services. `send()` does `container()->get(CommandBus::class)`. `SelfExecutingCommandMiddleware` method-injects each `handle()` param by `container->get($type)`. Tactician's handler middleware is given `@service_container`. In a Symfony kernel, private services are removed, so this fails at runtime with `UnresolvableHandleDependency` or `ServiceNotFound`. | `ddd-src/Application/CQRS/CommandBusAware.php:30-36`; `SelfExecutingCommandMiddleware.php:60,105-115`; `ddd-wordpress/di/tactician.yaml:65-81` (`@service_container`); `ddd-wordpress/di/services.yaml:8-11` (`public: true` blanket) | symfony | Medium. WP consumers depend on the public-everything YAML; keep it in ddd-wp. | Kernel test with private-by-default app services: a SelfHandlingCommand injecting a private repository executes; a plain command reaches its private handler. | ddd-symfony compiler pass builds two `ServiceLocator`s (handlers by `ddd.command_handler`/`ddd.query_handler` tags; `handle()` parameter types of every SelfHandlingCommand/Query) instead of passing `@service_container`. |
| S3 | `symfony/config` is used only to load YAML (`FileLocator` + `YamlFileLoader`), and `symfony/yaml` is **require-dev only**. The runtime self-consumer boot and the scaffolded consumer bootstrap therefore need a package the manifest does not require. | `composer.json:13,17,22`; `ddd-wordpress/self/index.php:17-19,35-39`; `ddd-wordpress/cli/class-ddd-command.php:337-351` | packaging / wp | High for WP packaging. A consumer without symfony/yaml fatals, unless other plugins' vendor trees happen to supply it. | Installed-package fixture: `composer require tangible/ddd-wp` in a clean project with no dev deps, then boot the self-consumer. | Does any live consumer ship without symfony/yaml? (the M0 inventory) |
| S4 | Listeners register themselves as a **constructor side effect**, calling the WP function `integration_listener()`. WP compensates by eagerly instantiating every service whose id matches `\Application\IntegrationListeners\` or `\Application\EventHandlers\`, using the `ContainerBuilder`-only `getServiceIds()`. Neither works in Symfony: the constructor fatals because the function is undefined, and the scan would instantiate half the container. | `ddd-src/Application/EventHandlers/IntegrationListener.php:26-31`; `ddd-wordpress/integration-events.php:77-104`; `ddd-wordpress/hooks.php:115-143` | core (make `IntegrationListener` pure: expose event class + translate) + wp (keep registration) + symfony (autoconfigure tag + compile-time subscription map) | High for WP. Every consumer listener extends this base; the constructor behaviour must stay in WP through a subclass or facade. | Core unit test constructs a listener without WP stubs. Symfony kernel test: an autoconfigured listener appears in the subscription map and is never constructed at boot. | Class-name ownership: core `IntegrationListener` versus a WP `IntegrationListener` facade (the spec's one-owner rule). |
| S5 | Process ignition and resume register through `add_action` inside `ProcessRunner`, at priorities 50 and 99. Plain listeners use priority 10. That ordering is a semantic contract: ignition consumes the triggering instance before any await sees it. | `ddd-src/Application/Process/ProcessRunner.php:60-99,123-184` (priorities `:95`, `:180`); `ddd-wordpress/hooks.php:282-320` | core (subscription registry port with explicit ordering) + symfony (Messenger handler priorities) | Medium. Losing the order changes which saga sees an event. | Messenger integration test: one fact that both starts process B and is awaited by suspended process A. B is ignited first, and A's await sees only later arrivals (mirrors `tests/Unit/Process/ProcessStartsOnTest.php`). | See T3 on retry skipping. |
| S6 | The base `Command` is hard-wired to the WP self-consumer container. The framework's own repair commands (replay, discard, retry, purge) therefore cannot dispatch in Symfony. | `ddd-src/Application/Commands/Command.php:10,24-26` | core (drop the import; resolve via `ConsumerRegistry`) + wp (self-consumer facade) | Medium. Self-audit rows in `wp_tangible_ddd_command_audit` rely on it. | Symfony kernel test: `ReplayDeadLetterCommand` dispatches on the TXP bus. | none |
| S7 | Two copies of the handler inflector exist. The WP YAML references `TangibleDDD\WordPress\DI\HandlerClassNameInflector`, while core ships `TangibleDDD\Application\CQRS\HandlerClassNameInflector`. | `ddd-wordpress/di/tactician.yaml:98-99`; `ddd-src/Application/CQRS/HandlerClassNameInflector.php:18-44`; `ddd-wordpress/self/index.php:21-23` | symfony uses core's; wp keeps its alias | Low | Unit test: both inflectors map the same fixture set. | none |
| S8 | Consumer identity is a static registry (`ConsumerRegistry`). `send()` resolves the owning consumer by namespace. A Symfony worker must populate it at kernel boot and must **not** clear it on message reset. | `ddd-src/Infra/Consumers/ConsumerRegistry.php:25-28,30-48`; `CommandBusAware.php:30-32` | symfony (`Bundle::boot()` registers the TXP consumer with a container getter) | Low | Kernel test: after 2 worker messages with `kernel.reset`, `send()` still resolves. | Does TXP need module handles (one per bounded context) or one consumer? Recommended: one consumer (`txp`); contexts are namespaces, not consumers. |

## 3. Adapter interface needs (ports ddd-symfony implements)

Each row is a responsibility the core must expose as a port, because today the code calls WP or AS directly. Names are working names, not frozen signatures.

| Port (working name) | Replaces today | Evidence | ddd-symfony implementation |
|---|---|---|---|
| `ITransactionBoundary` (`run(callable)`, `isActive()`) | `wpdb->query('START TRANSACTION')`, which silently no-ops without wpdb | `ddd-src/Application/Persistence/TransactionMiddleware.php:22-26,34-36,38-53` | `DbalTransactionBoundary` on the domain connection; flushes ORM if configured; rejects or savepoints a caller-owned transaction per config (section 5) |
| `IOutboxRepository` (existing) + an admin half + a fenced claim | wpdb SQL, option-backed pauses | `ddd-src/Infra/IOutboxRepository.php:14-127`; `ddd-src/Infra/Persistence/OutboxRepository.php:22-63,65-131,144-157,341-389` | `DbalPostgresOutboxRepository`: claim by `UPDATE … WHERE id IN (SELECT … FOR UPDATE SKIP LOCKED) RETURNING *` with a `lease_token`; `mark_completed(event_id, lease_token)`; pauses as rows |
| `IOutboxPublisher` (existing) | `as_enqueue_async_action` / `as_schedule_single_action` | `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php:18-35` | `MessengerOutboxPublisher` (section 7) |
| `IDomainEventDispatcher` (existing) | `do_action_ref_array` | `ddd-src/Infra/Services/WordPressEventDispatcher.php:15-33` | ordered synchronous dispatcher (PSR-14 `EventDispatcher` or a DDD-owned list) that keeps the `Reactions::open/close` frame |
| `IProcessLock` (`with(consumer, process_id, timeout, fn)`) | `global $wpdb; GET_LOCK` | `ddd-src/Application/Process/ProcessRunner.php:394-408` | Postgres session advisory lock (section 6) |
| `IProcessWakeScheduler` (continuation now; timeout at absolute UTC; durable intent) | `as_enqueue_async_action` / `as_schedule_single_action` after `save()` | `ProcessRunner.php:640-655,661-670` | intent rows written in the process-save transaction, relayed to `ddd_wakeups` (section 7) |
| `ISubscriptionRegistry` (listeners, ignitions, resumes; ordered) | `add_action` in the runner and in `integration_listener` | `ProcessRunner.php:77-96,148-181`; `ddd-wordpress/integration-events.php:88-104` | compile-time map from a compiler pass; consumed by the Messenger fact handler |
| `IProcessRepository` (existing) + `claim_ignition()` + keyed wait lookup | `has_ignition()`-then-insert; full scan of `waiting_for` | `ddd-src/Infra/IProcessRepository.php:10-41`; `ddd-src/Infra/Persistence/ProcessRepository.php:58-63,90-113` | `INSERT … ON CONFLICT DO NOTHING RETURNING id` on a unique `(process_class, ignited_by_event_id)`; `RETURNING id` instead of `insert_id` |
| `IAuditSink` + `IActorProvider` + `IEnvironmentProvider` + `IAuditPolicy` | `command_audit_*`, `get_current_user_id`, `is_multisite`, `get_bloginfo`, SHOW TABLES toggle | `ddd-src/Application/Correlation/CorrelationMiddleware.php:13-15,50-72,120-129`; `ddd-wordpress/audit.php:10-25,30-69` | DBAL audit sink; actor from Security token or console operator or machine actor; policy from attribute/config |
| `IFactObserver` (touches index) | `\TangibleDDD\WordPress\touches_index_fact()` | `ddd-src/Infra/Services/OutboxIntegrationEventBus.php:70-72` | null default; optional DBAL touches |
| `IInfrastructureNotifier` (PSR-14) | `do_action`, **silently skipped outside WP** | `ddd-src/Application/Infrastructure/InfrastructureEvent.php:46-53` | Symfony EventDispatcher; `ProcessFailed` and `OutboxDeadLettered` must be observable, not dropped |
| `ListenerPresence` (knowable / unknown) | `has_action()` at drain time | `ddd-src/Infra/Services/OutboxProcessor.php:69,78-94` | answered from the compiled subscription map (same codebase in relay and worker), else "unknown" |
| `OutboxConfig` split | `get_option()` factory and AS group/payload fields | `ddd-src/Application/Outbox/OutboxConfig.php:12-41` | container parameters; drop `action_scheduler_group` and `max_action_scheduler_payload_bytes` from core |
| `IDDDConfig` split | `hook()`, `as_group()`, `option()`, `table()` with the WP prefix | `ddd-src/Infra/IDDDConfig.php:11-71` | identity (prefix, version) + table naming; no hook/option vocabulary |
| `IRelayWakeup` (`poke()` after commit) | nothing (30 s AS recurring poll) | `ddd-wordpress/hooks.php:199-209` | Postgres `NOTIFY` (section 7, D14) |

## 4. Findings (defects and seams that bite a Symfony host)

| # | Claim | Evidence | Proposed owner | Compatibility risk | Test that proves it | Unresolved |
|---|---|---|---|---|---|---|
| F1 | **Lost arrivals under concurrent workers.** `resume_on_event` loads waiting processes *before* taking the lock, then accumulates and saves the stale instance inside the lock without re-reading. Two workers delivering two different keys of one `AwaitAll` both start from the same `gathered` set, and the last save wins. `handle_timeout` does re-read under the lock; resume does not. | `ddd-src/Application/Process/ProcessRunner.php:290-326` (read at `:292`, mutate at `:303-316`) versus `:345-353` | core | Low. It is a fix, and WP AS runners can hit it too. | Postgres conformance: 2 workers deliver 2 keys of a 2-key AwaitAll concurrently, then assert that the process resumes exactly once with both keys gathered. | none |
| F2 | **The await is persisted after the step's commands are dispatched.** Each command commits in its own transaction and writes its facts to the outbox. The process is saved as suspended only afterwards. A crash in between leaves committed side effects and a process not waiting for their result. A fast relay can also deliver the result before `waiting_for` is written, so nothing resumes (the resume listener exists, so no `FactDeliveredUnheard`). | `ProcessRunner.php:473` (dispatch) then `:476-478` then `:633-646` (save suspended); `:619-628` | core (reorder: persist the await, then dispatch; add a precheck hook), which is D3 | Medium. It changes the step contract and needs a changelog entry. | Conformance: a synchronous-transport fixture where the awaited fact is delivered inside `dispatch_commands`; the process must still resume. | Is the precheck (`already_satisfied(): bool` on the process) a core API or TXP convention? |
| F3 | **`command_id` is random per dispatch.** A Messenger redelivery of the same fact runs the listener again and mints a new command id, so any journal keyed on command id misses. TXP's module map keys the D1 effect journal "by command_id". | `ddd-src/Application/Correlation/CorrelationMiddleware.php:48`; `txp:.docs/specs/module-map.md:228` | core (deterministic command id when dispatched inside a Fact cause: `uuid5(event_id, listener class)`; or journal keyed by the declared idempotency key) | Low. `command_audit.command_id` is CHAR(32) hex (`ddd-wordpress/tables.php`, command_audit DDL), and a uuid5 without hyphens fits. | Deliver the same fact twice and assert the listener's command gets the same id both times. The D1 perform runs once. | Which is authoritative for D1: the idempotency key or the command id? Recommended: the declared key; command id for tracing only. |
| F4 | **The relay completion is unfenced.** `mark_completed` updates by `event_id` only, so a worker whose claim expired can still mark a row another worker re-claimed. The claim hardcodes a 300 s lease, while `release_stale_locks` subtracts `lock_timeout_seconds` again from `locked_until`. The effective lease is therefore lease + timeout, not the config value. | `ddd-src/Infra/Persistence/OutboxRepository.php:77,121-126,144-157,273-283`; `ddd-src/Infra/Services/OutboxProcessor.php:47` | core (contract: claim returns a token; completion requires it) + symfony (SQL) | Low | Two relays: A claims, lease expires, B claims and publishes. A's late `mark_completed` must affect 0 rows. | none |
| F5 | **Double delay (known bug 3).** `write()` sets `scheduled_at = now + delay` and the claim waits for it. The AS publisher then adds `delay_seconds` again. The Symfony publisher must not attach a `DelayStamp` for `delay_seconds`. | `OutboxRepository.php:31-32,97`; `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php:21-27` | core (single-delay contract: the outbox gates on absolute `scheduled_at`) + symfony + wp fix | Low | Conformance "delayed event": a 60 s delay is handled at ~60 s, not ~120 s (fake clock). | none |
| F6 | **Unlocked critical section (known bug 1)** and a lock namespace that is not per-consumer. `GET_LOCK` returns NULL on error, which casts to `''` and passes the `=== '0'` check. The name is `ddd_process_<id>`, and MySQL named locks are server-global, so two consumers' process 7 serialize with each other. | `ProcessRunner.php:396-401`; process ids are per-table autoincrement (`ddd-wordpress/tables.php`, `install_process_tables`, `id BIGINT … AUTO_INCREMENT`) | core (port contract: only a definite `true` enters) + wp fix + symfony (section 6) | Low | Lock fake returning null/false/error: the callback is never invoked and the exception is retryable. | none |
| F7 | **Ignition check-then-insert (known bug 2).** The `idx_ignition` index is not unique, so two workers delivering the same fact can both pass `has_ignition`. | `ProcessRunner.php:166-175`; `ProcessRepository.php:90-100`; `ddd-wordpress/tables.php` (`KEY idx_ignition (ignited_by_event_id)`) | core (atomic `claim_ignition`) + symfony (partial unique index) | Low. Additive index, but duplicates may exist in WP tables and must be found before adding UNIQUE there. | 2 concurrent deliveries of one igniting fact create exactly one process row. | none |
| F8 | **Timeout and continuation scheduling is "save then enqueue".** No intent row is written, and a relative delay is computed at enqueue time. | `ProcessRunner.php:646-655,662-669` | core (intent contract) + symfony (DBAL `ddd_process_wakeups`) | Low | Kill the process between save and enqueue (fake scheduler throws). The wakeup is still delivered later. | none |
| F9 | **Messenger retries re-run the whole fact subscriber set** unless per-handler skipping works. WP runs listeners, ignitions and resumes in one AS action. An exception aborts the rest of `do_action`, and AS does not retry. Under Messenger, a retry of one message re-runs every subscriber that already succeeded, unless those handlers are recorded as handled. | `ddd-wordpress/integration-events.php:53-63`; `ProcessRunner.php:433-441` (runner rethrows after marking failed) | symfony | Medium. It is a semantic difference from WP, which has no retry. | Messenger kernel test: listener A succeeds, listener B throws once. On retry A is not called again; B is. | Verify that Symfony 7.4's `HandleMessageMiddleware` skips handlers already carrying a `HandledStamp` on a retried envelope. If it does not, use one message per (fact, subscriber) (section 7). |
| F10 | **ProcessRunner is not transport-neutral.** It calls `add_action`, `as_*` and `global $wpdb` directly, and its WP-coupled lines are exactly the ports in section 3. `ProcessRunner` also keeps transient state (`resume_argument`, `started_at`) in a service that a worker reuses. | `ProcessRunner.php:35-41,77,148,395-407,649,665`; `ddd-src/Application/Process/RescheduleAware.php:9-11` | core | Medium | Core unit test runs start → suspend → resume with in-memory ports and no WP stubs. | none |
| F11 | **Process and outbox payload codecs are Postgres-hostile for binary.** Payloads go through JSON with no size cap or quarantine. `payload_bytes` is recorded but never enforced (it only routes in `RoutingOutboxPublisher`). Postgres `jsonb` rejects the `\u0000` code point, and invalid UTF-8 fails `json_encode`. | `OutboxRepository.php:33-34`; `ddd-src/Infra/Services/RoutingOutboxPublisher.php:40-51`; `ddd-src/Domain/Events/IntegrationBehaviour.php:68-78` | core (D6 codec: an explicit base64 wrapper type, a cap checked at write so the command fails before commit) + symfony (`json`/`text` columns, not `jsonb`, for payloads, or rely on the codec) | Low | Publish a fact with a 1 MB binary string field. It either round-trips via the codec or fails before commit with a named error. Never a silent `false` payload. | Cap value per transport (the Doctrine transport body is `text`, with no hard limit). |
| F12 | **Hydration instantiates stored class names blindly** (process class, await mechanism class). A renamed or removed class is a runtime fatal, not a quarantine. | `ProcessRepository.php:155-160,174-179,211-231` | core (class alias map + quarantine status) | Medium for WP stored rows | A stored row with an unknown `process_class` ends up `quarantined` with a reason, and the worker continues. | none |
| F13 | `fetch_pending` opens its own `START TRANSACTION` on the shared connection. Called inside an open transaction on MySQL this implicitly commits the outer one. On DBAL it must use nesting-aware `transactional()`, or be a single autocommit `UPDATE … RETURNING`. | `OutboxRepository.php:107-128` | symfony (single statement) | none | Relay called inside a test transaction does not commit the outer transaction. | none |
| F14 | `find_waiting_for` loads **every** suspended process for an event class and filters in PHP with `accepts()`. With keyed awaits (D3) across thousands of TXP processes this is a table scan per fact. | `ProcessRepository.php:102-113`; `ProcessRunner.php:292-297` | core (mechanism exposes an optional routing key) + symfony (indexed `await_key` column) | Low (additive column) | 10k suspended processes; resume latency stays bounded; EXPLAIN uses the index. | none |

## 5. Physical transaction ownership: DBAL connection versus command middleware

**Recommendation.** The physical transaction belongs to the DBAL `Connection` that the domain repositories and the outbox share. The decision of *which* commands are transactional, and *when* to begin and commit, stays in core's `TransactionMiddleware`, through `ITransactionBoundary`. Messenger's `doctrine_transaction` middleware must not wrap anything that dispatches DDD commands.

Reasons, from code:

1. The command-pipeline order the extraction spec wants to preserve lives in Tactician: Correlation → Transaction → DomainEventsPublish → SelfExecuting → Handler (`ddd-wordpress/di/tactician.yaml:21-36`). Reactions and outbox writes run inside the transaction because DomainEventsPublish sits inside Transaction (`ddd-src/Application/Events/DomainEventsPublishMiddleware.php:14-33`). The audit finalise runs outside it (`CorrelationMiddleware.php:90-106`).
2. A fact reaches TXP code through Messenger → listener → `$command->send()` → Tactician (`ddd-wordpress/integration-events.php:96-101`). If Messenger also opened a transaction:
   - the Tactician transaction would become a DBAL savepoint;
   - a process wake that dispatches several step commands (`ProcessRunner.php:619-628`) would collapse into one transaction;
   - the lock analysis in section 6 would change silently.

   ddd-symfony's health check should fail if a bus consuming `ddd_*` transports carries `doctrine_transaction`.
3. ORM: if TXP repositories use the ORM, the boundary adapter must use `EntityManager::getConnection()` (the same connection) and `flush()` before commit. Messenger's Doctrine transport must use `doctrine://default` so the relay hand-off can share that connection (section 7). On failure, `clear()` the unit of work. Do not rely on ORM helpers that close the EntityManager, because a closed EM poisons every later message in a long-running worker. Verify the ORM 3 `wrapInTransaction` close-on-throw behaviour before choosing it.
4. Caller-owned transactions: DBAL nests via savepoints. The current middleware just issues `START TRANSACTION` (`TransactionMiddleware.php:39`), which on MySQL implicitly commits any outer transaction.

   Proposed contract: a `nested: reject|savepoint` setting.
   - Production default `reject`: throw before the handler runs if `isTransactionActive()`.
   - Test harnesses that wrap each test in a transaction (DAMA-style) use `savepoint`.

   `NOTIFY` and session locks behave differently under a wrapping test transaction, so the conformance suite for D4/D14 must run **without** the wrapper.
5. There must be no silent fallback. Today a missing wpdb runs the handler without a transaction (`TransactionMiddleware.php:34-36`). ddd-symfony fails container compilation if an `ITransactionalCommand` class exists and no boundary is bound.
6. The relay owns a second, short transaction on the same connection: the Messenger send plus `mark_completed(token)` (section 7). Process-state saves plus wake-intent rows need a third, inside `IProcessRepository::save` (F8).

## 6. Postgres lock choice: session versus transaction advisory

What the lock must do, from the code:

- It must serialize every wake of one process (start, continuation, resume, timeout) through `with_process` (`ProcessRunner.php:248-261`).
- It must be **re-entrant**: `handle_timeout` takes the lock, then `with_process` takes it again (`:345`, `:364`, comment at `:360-363`). `tests/Unit/Process/AwaitTimeoutTest.php:109-124` asserts the balance.
- A wake spans several independently committed commands: each `send()` runs its own `TransactionMiddleware` transaction (`:619-628`), plus autocommit `save()` calls (`:481-488`).
- On failure to acquire it must not enter, and the failure must be retryable (`:399-401`).

| Option | Lifetime | Fits the wake? | Re-entrant | Failure modes |
|---|---|---|---|---|
| `pg_advisory_xact_lock` | until the current transaction ends | **No.** In autocommit it is released at statement end. Inside a command transaction it is released at that command's commit, mid-wake. It only works if the whole wake becomes one transaction, which would make step commands atomic with each other and with process state, and would hold a transaction across the wake. That contradicts the current step/compensation model, which assumes earlier steps' commands are committed (`:499-504`, `:516-586`). | yes (within the transaction) | none, if the model were changed |
| `pg_advisory_lock` / `pg_try_advisory_lock` (session) | until explicit unlock or the connection closes | **Yes.** Same lifetime as MySQL `GET_LOCK`. | yes (stacked; must unlock as many times) | Leaks if unlock is skipped, which `finally` covers. Breaks behind a transaction-pooling proxy (PgBouncer transaction mode, the Neon `-pooler` endpoint), because the lock sticks to a pooled backend another client gets. A dropped connection releases it, which is safe for crashes. |
| Row lock `SELECT … FOR UPDATE` on the process row | transaction | Same problem as xact | n/a | same as xact |
| Symfony Lock `PostgreSqlStore` | session advisory underneath | Yes | **No** re-entrancy guarantee matching the nested acquisition | adds a dependency for no gain |

**Recommendation (for D4 and review finding 9, to settle at M3 contract freeze):**

1. Use a **session-scoped** `pg_try_advisory_lock(bigint)`, polled against a deadline (default 5 s, matching `GET_LOCK(…, 5)`). On timeout throw a retryable `ProcessLockUnavailable`; a normal Messenger retry applies.
2. Key it as `(crc32(consumer_prefix) << 32) | (process_id & 0xffffffff)`. A hash collision only adds serialization, because state is always re-read under the lock (F1).
3. Check the boolean result explicitly. Unlock in `finally`; `pg_advisory_unlock` returning `false` is logged as a bug.
4. Workers and the relay must use a **direct, non-pooled connection**. Document this as a hard prerequisite and give TXP a boot-time warning when the DSN host matches a known pooler pattern.
5. Revisit transaction-scoped locking only if a later design makes a wake one transaction.

Row locks for TXP invariants (staging cap, name registry, D4's second half) are ordinary `FOR UPDATE` inside a command transaction and belong to the TXP app.

## 7. Messenger transport mapping for relay and wakeups

**Transports** (all on the Doctrine transport, `doctrine://default`, Postgres, `use_notify: true`):

| Transport | Message (DDD-owned class) | Producer | Consumer handlers |
|---|---|---|---|
| `ddd_facts` | `IntegrationFactMessage{consumer, event_id, event_type, integration_action, wrapped_payload}`. `wrapped_payload` is exactly what `IntegrationEnvelope::wrap` produces today (`ddd-src/Application/Events/IntegrationEnvelope.php:30-36`), so the envelope stays compatible. | relay (`OutboxProcessor` + `MessengerOutboxPublisher`) | one Messenger handler per subscriber kind, in priority order: listeners (the WP 10 slot) → ignitions (50) → resumes (99). Each runs the drain bracket: `unwrap` → `Correlation::within(trace_context()->for_fact(event_id))` → work (mirrors `ddd-wordpress/integration-events.php:88-103`, `ProcessRunner.php:80-93,151-178`). |
| `ddd_wakeups` | `ContinueProcess{consumer, process_id, expected_step}`, `ProcessTimeout{consumer, process_id, step_index}` | relay of the wake-intent rows (F8, D7) | `ProcessRunner::continue_scheduled` / `handle_timeout` (`ProcessRunner.php:266-285,332-388`) |
| `ddd_failed` | failure transport | Messenger | `ddd:dlq` console (D9) |

**Hand-off atomicity.** Because the Doctrine transport writes `messenger_messages` on the same DBAL connection, the relay can run `publish()` and `mark_completed(event_id, lease_token)` inside **one** transaction. Transport acceptance then becomes exactly-once. Today completion is marked after a non-transactional enqueue, which is the known duplicate window (`OutboxProcessor.php:72-73`, the extraction spec's §2). Handler execution stays at-least-once, so listeners and effects still need idempotency. With a non-Doctrine transport (AMQP, Redis) this degrades to the current at-least-once hand-off. Record that as a capability flag rather than assuming it.

**Delay.** The outbox gates on absolute `scheduled_at`. The publisher never adds a `DelayStamp` for `delay_seconds` (F5). Long alarms (D7) are intent rows with an absolute `due_at timestamptz`, relayed when due. There is no multi-day `DelayStamp`, so pauses and repair tools see pending alarms in one place.

**Retry budgets (review finding 7, D9).**

- The relay budget (`OutboxConfig::max_attempts`, `OutboxConfig.php:14`) covers only hand-off failures, which on the same-connection Doctrine transport are DB errors.
- The handler budget is the transport's `retry_strategy`.
- The two are separate, and an operator sees both in one view. D1 failure commands trigger when the handler budget is exhausted (`WorkerMessageFailedEvent` with `!willRetry()`).

**Handler granularity.** If F9's verification shows Messenger does not skip already-handled handlers on retry, change the relay to write one message per (fact, subscriber id). The subscriber map is compile-time, so it is knowable, but that multiplies rows and loses the in-message ordering between ignition and resume. Test first, then decide.

**Post-commit wakeup (D14).**

1. The Postgres outbox `write()` issues `NOTIFY ddd_outbox, '<consumer>'` inside the command transaction. Postgres delivers it only on commit and drops it on rollback, which gives exactly the post-commit semantics.
2. The relay worker (`ddd:relay`) blocks on `LISTEN ddd_outbox`, with a poll timeout equal to `processor_interval_seconds` as the lost-wakeup fallback, as the extraction spec requires.
3. The Messenger consumer already gets LISTEN/NOTIFY from the Doctrine Postgres transport.
4. Plain-PHP / pdo-default hosts get no daemon, per the operator decision. Core exposes `drain(int $max): ProcessingResult` (today's `process_batch()`, `OutboxProcessor.php:45-125`) and the host schedules it from cron or end-of-request.

## 8. Worker reset between messages

Messenger resets services tagged `kernel.reset` between messages. ddd-symfony must register one `DddRuntimeReset` (`ResetInterface`) and one guard listener.

| State | Evidence | Reset action | Must NOT reset |
|---|---|---|---|
| `EventsUnitOfWork` queued/published/sealed | `ddd-src/Application/Events/EventsUnitOfWork.php:23-35` (`published` keeps event objects alive until the next command, which also keeps `Reactions`/`PublishedFacts` WeakMap entries alive) | `reset()` | none |
| `Correlation` static current/stack/sequence | `ddd-src/Application/Correlation/Correlation.php:23-28,44-56,64-68` | assert `peek() === null` and an empty stack (fail loud: a leak means a bracket bug), then `reset()` | none |
| `Reactions` static stack + WeakMap | `ddd-src/Application/Events/Reactions.php:24-27,70-73` | `reset()` | none |
| `PublishedFacts` WeakMap | `ddd-src/Application/Events/PublishedFacts.php:21-34` | none needed (WeakMap); covered by the UoW reset | none |
| `ProcessRunner` transient `resume_argument`, `started_at` | `ProcessRunner.php:40-41,313-320,338,421`; `RescheduleAware.php:9` | clear both | `registered_events`, `registered_starts` (`:35-38`, boot-time) |
| `ConsumerRegistry` statics | `ddd-src/Infra/Consumers/ConsumerRegistry.php:25-28` | none | whole registry (boot-time) |
| `IntegrationBehaviour::record_schema` static cache | `ddd-src/Domain/Events/IntegrationBehaviour.php:97-101` | none (pure reflection cache) | yes, keep |
| `JsonLifecycleValue` static renderer | `ddd-src/Domain/Shared/JsonLifecycleValue.php:26` | none (boot configuration) | keep |
| `command_audit_enabled` static cache | `ddd-wordpress/audit.php:11-24` | WP only; the Symfony audit policy is a service | n/a |
| Advisory locks held | section 6 | assert none held at message end (a counter in `IProcessLock`) | none |

Plus Messenger middleware on the DDD buses: `doctrine_ping_connection` (keeps the session alive for locks and LISTEN) and `doctrine_clear_entity_manager`. Do not add `doctrine_close_connection` to the relay worker, because it drops `LISTEN`. Sequential workers only: the extraction spec's §4 fiber caveat stands.

## 9. D1-D14: demand → exists today? → proposed owner

| D | Demand (module-map) | Exists in library today? | Evidence | Proposed owner | Notes / test |
|---|---|---|---|---|---|
| D1 | ExternalEffect: `perform()` outside the transaction with a declared idempotency key and a journaled result; `record()` inside the Transaction middleware; a retry reuses the journal; a declared failure command runs on budget exhaustion | **No** | No effect or journal types in `ddd-src`. `ITransactionalCommand` is all-or-nothing (`ddd-src/Application/Commands/ITransactionalCommand.php:8-10`). `command_id` is random per dispatch (F3). | core: command shape + `IEffectJournal` port + an `EffectMiddleware` between Correlation and Transaction. symfony: DBAL journal table + failure-command trigger on `WorkerMessageFailedEvent(!willRetry)`. TXP: the Stripe and Cloudflare effects. | Test: perform succeeds, record throws, redelivery → perform is not called again and record runs with the journaled result. Budget exhausted → the failure command commits once. |
| D2 | Listener subscription by marker interface (IRequestsNotification, IOrdersRunnerJob, …) | **No** | Hooks resolve per concrete class: `IntegrationHookName::resolve` → `$event_class::integration_action()` (`ddd-src/Infra/Consumers/IntegrationHookName.php:35-41`, `IntegrationBehaviour.php:25-27`). A listener declares one class (`IntegrationListener.php:21`). | core (subscription registry matches on `is_a` against the fact's class) + symfony (compile-time map). WP later: it would need boot-time expansion to concrete hooks. | Test: two fact classes implementing one marker both reach one listener, once each. |
| D3 | Keyed await: persisted before dispatch, register-then-check precheck, any-of including cancellation facts, AwaitAll over a checkpointed dynamic key set | **Partial** | Keyed match exists (`AwaitEvent` `match_criteria`, `ddd-src/Application/Process/AwaitEvent.php:36-46`). AwaitAll takes a runtime-supplied expected set (`AwaitAll.php:30-37,56-63`). Missing: ordering (F2); precheck; any-of, since the process row stores a single `waiting_for` class (`ProcessRepository.php:41`, `tables.php` process DDL `waiting_for VARCHAR(255)`) and `find_waiting_for` matches one class (`:102-113`); a timeout on the single `AwaitEvent` (`AwaitEvent.php:51`); indexed routing (F14). | core (mechanisms + runner ordering + a multi-class wait index contract) + symfony (a `process_waits(process_id, event_class, await_key)` table or an array column with an index) | Tests: the F2 synchronous fixture; AnyOf(BackupReplicated, BackupFailed, BackupReplicationFailed) resumes on whichever arrives first and ignores the rest; a precheck absorbs a fact that committed before suspension. |
| D4 | Postgres advisory-lock scope for the per-process wake lock; row locks for the staging cap / name registry | **No** (MySQL `GET_LOCK` only, with bug F6) | `ProcessRunner.php:394-408` | core (`IProcessLock` contract) + symfony (session advisory, section 6). Row locks: TXP app. | Test: 2 workers wake one process concurrently and are serialized. Kill a worker holding the lock and the lock is released. Re-entrant nested acquisition is balanced. |
| D5 | Actor provider: session user, console operator, machine actors (runner hostnames, cron, ssh gateway, webhook provider) with a distinct audit kind | **No** (hard-wired WP) | `CorrelationMiddleware.php:120-129` (`cli` / `system` / `user` via `get_current_user_id`) | core (`IActorProvider`, an open source-type set including `machine`) + symfony (Security token + console `--operator`) + TXP (machine authenticators set the actor) | Test: a command dispatched under a machine firewall writes an audit row with source `machine:<host>`. |
| D6 | Large scalar string codec (payload_json) with a size cap and quarantine | **No** | F11 | core (codec + cap + quarantine status) + symfony (column types) | F11 test |
| D7 | Durable absolute-UTC alarms of 24 h or more, backed by intent rows, with a single-delay test | **No** | Relative `as_schedule_single_action(time()+timeout)` after save (`ProcessRunner.php:648-655`); the double delay on facts (F5) | core (intent contract; absolute `due_at`) + symfony (DBAL wakeups table relayed to `ddd_wakeups`) | Tests: fake clock + a 25 h alarm survives a worker restart and fires once; delayed fact single delay (F5). |
| D8 | Audit redaction hook for passwords, tokens, PEMs; binary bodies not serialised | **Partial** | `Redactor` masks a fixed key list and summarises strings over 1024 bytes (`ddd-src/Application/Logging/Redactor.php:19-21,142-165,200-207`). The list is not extensible. It lacks `private_key`, `pem` and `license`. It sees only `get_object_vars($command)` (`CorrelationMiddleware.php:54`). | core (configurable keys + a `#[Sensitive]` / `#[NotAudited]` property attribute) | Test: a command with a `private_key_pem` property audits as masked; a 5 MB body audits as a summary. |
| D9 | Unified operator view: outbox DLQ, Messenger failure transport, stuck processes, unacked job outcomes; handler budget visible separately from relay retries | **Partial** | Outbox DLQ + repair handlers exist (`OutboxRepository.php:231-271`; `ddd-src/Application/CommandHandlers/{Replay,Discard,Retry,Purge}*Handler.php`). Infrastructure signals are dropped outside WP (`InfrastructureEvent.php:47-49`). | symfony (`ddd:dlq:list/replay` merging the outbox DLQ + a `ListableReceiverInterface` failure transport + processes stuck past `due_at`; PSR-14 notifier). TXP: unacked-job view (gateway). | Test: one failure per layer shows up in one listing with its layer label. |
| D10 | BehaviourWorkflow ignition from a fact (`#[StartsOn(CronEntryDue)]`) with a (workflow, minute) dedup key | **Partial** | `#[StartsOn]` exists for LongProcess only (`ddd-src/Application/Process/StartsOn.php:23-29`; `ProcessRunner.php:123-184`), deduped non-atomically on event id (F7). Workflows are command-driven (`ddd-src/Application/BehaviourWorkflows/WorkflowHandler.php:30,59`). | core (atomic ignition ledger with a caller-supplied dedup key; a StartsOn-equivalent for workflows, or the documented listener → command pattern) + TXP (the dedup key) | Test: the same `CronEntryDue` delivered twice, and two ticks in one minute, start one workflow run. |
| D11 | Command return values through Correlation, Transaction and DomainEventsPublish | **Yes** | `CommandBusAware.php:34-36`; `TransactionMiddleware.php:41-45`; `DomainEventsPublishMiddleware.php:17,33`; `CorrelationMiddleware.php:80-83`; `SelfExecutingCommandMiddleware.php:91-93` | core (already). A doctrine conflict needs a ruling: the SelfHandlingCommand "receipt rule" says nothing downstream may depend on the return (`ddd-src/Application/Commands/SelfHandlingCommand.php:45-48`), while TXP depends on it (AcquireJob, checkout URL). | Test exists in spirit (`tests/Integration/Framework/CommandBusE2ETest.php`); add a Symfony kernel test returning a DTO through the full chain. |
| D12 | Per-command audit policy (heartbeat/progress/acquire-miss off; guards kept) | **No** (per-consumer table-presence toggle) | `ddd-wordpress/audit.php:10-25`. The guard is independent of audit (`CorrelationMiddleware.php:41-47` runs before `$audit` is consulted) and must stay so. | core (`IAuditPolicy`) + symfony (attribute/config) | Test: an audit-off command still throws `CommandDispatchedInsideCommand` when nested. |
| D13 | Cause event_id access inside a listener; UUIDv5; `process_id` + `step_index` for deterministic job ids | **Partial** | Inside a drain the ambient cause is the Fact with `id = event_id` (`ddd-wordpress/integration-events.php:91-93`; `TraceContext.php:34-36`), reachable via `Correlation::peek()->cause`, but `get_command(event)` gets no envelope (`IntegrationListener.php:24`). Only v4 exists (`ddd-src/Domain/Shared/Uuid.php:14-23`). `get_id()` / `current_step_index()` exist (`ddd-src/Domain/Shared/Entity.php:17`; `LongProcess.php:177`). | core (`Uuid::v5`, an explicit `Cause`/fact accessor for listeners, deterministic command id per F3) + symfony (the Messenger drain bracket must reproduce `within(for_fact)`) | Test: a listener reads the event_id of the fact it is translating; `uuid5(process_id, step)` is stable across a wake retry. |
| D14 | Post-commit relay wakeup, with outbox polling still recovering a lost wakeup | **No** (30 s AS recurring poll) | `ddd-wordpress/hooks.php:199-209` | core (`IRelayWakeup` no-op default; `drain()` entry point) + symfony (transactional `NOTIFY` + `LISTEN` relay with poll fallback, section 7) | Tests: commit → relay handles the row in under 1 s; with NOTIFY suppressed, the poll still delivers within the interval; rollback → no wakeup. |

Placement answer for Gate 1 decision 10 (`txp:.docs/specs/module-map.md:1184`): the D-ports are generic. D1, D2, D3, D6, D7, D8, D10, D12 and D13 are **core contracts** with Symfony implementations. Only D4's Postgres lock, D9's merged view and D14's NOTIFY are Symfony-specific. Building them as TXP-local adapters would fork exactly the semantics the conformance suite freezes, which agrees with the decomposition rollup (`txp:.docs/research/2026-09-30-domain-decomposition/00-rollup.md:73`).

## 10. Reference scenario (M4 gate, TXP `tenancy-reference` + `process-kernel` toy)

Happy path:

1. HTTP `AcceptInvite(teamId, actorUserId, token)` → Tactician:
   - Correlation (audit row open, actor from Security) → Transaction (DBAL begin on `default`) → DomainEventsPublish → handler.
   - The handler loads and saves `TeamInvite` through the ORM/DBAL repository on the same connection and records the domain event `InviteAccepted`.
   - Seal. The ordered in-transaction reaction creates `Membership` and announces the fact `MembershipGranted`.
   - `OutboxIntegrationEventBus` writes a `ddd_outbox` row (correlation, sequence, `command_id`) and issues `NOTIFY ddd_outbox`.
   - ORM flush → commit → audit finalise (outside the transaction).
2. `ddd:relay` wakes on NOTIFY and claims the row with a lease token. In one transaction it inserts the `IntegrationFactMessage` into `messenger_messages` and marks the outbox row completed with that token. Commit.
3. `messenger:consume ddd_facts` runs the handlers in order:
   - The listener handler unwraps and enters `within(for_fact(event_id))`. The toy listener's `get_command` returns `OrderToyJob(teamId)`. The command id is deterministic: `uuid5(event_id, listener)`.
   - The ignition handler: toy process `ToyProvision` `#[StartsOn(MembershipGranted)]`, atomic `claim_ignition(process_class, event_id)`. Its first step persists `AwaitEvent(ToyJobFinished, {job_id: uuid5(process_id, 0)})` with a 30 min timeout intent row, **then** dispatches `OrderToyJob`.
4. `ToyJobFinished` is announced by another command → outbox → relay → the resume handler:
   - takes `pg_try_advisory_lock(key(txp, process_id))`;
   - **re-reads** the process;
   - checks `accepts` / `accumulate`, advances and completes;
   - unlocks in `finally`.
5. The timeout intent is delivered later via `ddd_wakeups` and is ignored as stale (`current_step_index` has moved on).

Required variants (the extraction spec's M4 bullet plus the TXP risks):

| Variant | Expected |
|---|---|
| Commit fails (inject a failure on COMMIT) | No invite change, no membership, no outbox row, no NOTIFY; the original exception surfaces; the audit row shows `error`. |
| Reaction throws | Full rollback; no fact is relayed. |
| Relay crashes after the Messenger insert and before commit | Both roll back. The row is re-claimed after the lease; one message total. |
| Duplicate delivery of `MembershipGranted` (worker killed before ack) | The listener's command runs again with the same deterministic id (idempotent handler or D1 journal). The ignition claim returns false, so one `ToyProvision`. |
| Two workers deliver `ToyJobFinished` and the timeout concurrently | Serialized by the advisory lock; exactly one outcome; the stale timeout does nothing. |
| Worker restart mid-suspension | Process row + intent row survive; the new worker resumes; no leaked Correlation, UoW or lock state (guard listener passes). |
| Two messages in one worker, first fails | The second message sees a clean `Correlation::peek() === null`, an empty UoW and a null `resume_argument` (the extraction spec's "no leaked trace" scenario). |
| NOTIFY suppressed | The poll fallback delivers within `processor_interval_seconds`. |
| Pooled DSN configured for workers | The boot health check warns. The conformance run is documented as unsupported. |

## 11. Consequences for the other packages

- **core:** the fixes F1, F2, F4-F8 and F10 are core behaviour changes. The three operator-scheduled bugs (F5, F6, F7) are a subset. F1, F2 and F4 are additional correctness defects with the same blast radius, and they should join the same fix-and-hotfix track.
- **pdo-default:** given the operator decision and the user note ("maybe just supposing there's a `$db` conn somewhere"):
  - Offer a thin adapter taking a host `PDO` for the transaction boundary, outbox, process repository and lock, plus `drain()` / `runWakeups()` entry points the host calls from cron or end-of-request.
  - It must not be reused by ddd-symfony. The SQL dialects and the lock semantics differ (sections 6 and 7).
  - It shares only the core conformance suite.
- **wp:** keeps `GET_LOCK` (fixed), AS scheduling, the option-backed pauses (or migrates them to rows) and the constructor-registering `IntegrationListener` facade (S4). It also needs `symfony/yaml` moved to `require` (S3).
- **packaging:** the Symfony DI/config dependencies leave core entirely. Only the WP and Symfony packages require them (S1, S3).

## Open questions

1. Symfony 7.4 Messenger: on retry, does `HandleMessageMiddleware` skip handlers that already have a `HandledStamp`? This decides between one message per fact with multiple handlers and one message per (fact, subscriber) (F9). It needs a spike test, not documentation.
2. D1 journal key: the declared idempotency key (recommended) or a deterministic command id? TXP's module map says "keyed by command_id", which is incompatible with today's random `command_id` (F3). Is the journal per context (`effect_journal_billing`), as TXP lists it, or one library table?
3. D11 versus the SelfHandlingCommand receipt rule (`SelfHandlingCommand.php:45-48`): will the owner rule that command return values are a supported contract for plain commands, self-handling commands, or both?
4. Transaction nesting default: is `reject` in production and `savepoint` in tests acceptable? Does TXP intend to use a per-test transaction wrapper? If it does, the D4/D14 conformance tests need a separate non-wrapped suite.
5. Does TXP's Postgres (Neon or self-hosted) give workers a direct, non-pooled endpoint? Session advisory locks and LISTEN both require one.
6. Is F2 (persist the await before dispatching the step's commands) accepted as a core behaviour change in 0.6.x hotfix scope, or only on the extraction branch? It changes the observable order for existing WP sagas.
7. Is any-of await (D3) a new mechanism with a multi-class wait index, or a convention of one "outcome" fact class per await? The first needs a schema change to the process table on WP as well.
8. For D2 marker subscription, is WP support required at the same time? It needs boot-time expansion of markers into concrete hook names, or it stays Symfony and core only.
9. Should `IDDDConfig` be split now (identity and table naming in core, hook/option/AS vocabulary in WP), or wrapped by a Symfony adapter that throws on `hook()` / `as_group()` / `option()`? Splitting is cleaner, but every WP consumer's generated `Config` implements the current interface.
10. Does ddd-symfony ship its schema as Doctrine Migrations classes TXP copies in, or as SQL provided by a `ddd:schema:dump` command TXP pastes into its own migrations? The operator ruled out a migration framework in core; ddd-symfony's stance still needs a ruling.
